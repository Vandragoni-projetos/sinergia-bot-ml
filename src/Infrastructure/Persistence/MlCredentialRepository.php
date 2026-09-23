<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Port\MercadoLivre\CredentialStore;
use Sinergia\Application\Port\MercadoLivre\StoredCredential;
use Sinergia\Application\Port\MercadoLivre\TokenSet;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Infrastructure\Crypto\SecretBox;

/**
 * Implementação MariaDB de CredentialStore. Tokens entram e saem cifrados;
 * o texto puro só existe em memória, dentro de SensitiveValue.
 */
final class MlCredentialRepository implements CredentialStore
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly SecretBox $box,
    ) {
    }

    public function save(InstallationId $installation, string $clientId, TokenSet $tokens, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ml_credentials
                (installation_id, client_id, ml_user_id, scopes, access_token_enc, refresh_token_enc, key_id,
                 access_expires_at, refresh_obtained_at, status, last_refresh_at)
             VALUES (:inst, :client, :user, :scopes, :access, :refresh, :key, :expires, :robtained, \'connected\', :now)
             ON DUPLICATE KEY UPDATE
                client_id = VALUES(client_id), ml_user_id = VALUES(ml_user_id), scopes = VALUES(scopes),
                access_token_enc = VALUES(access_token_enc),
                refresh_token_enc = COALESCE(VALUES(refresh_token_enc), refresh_token_enc),
                refresh_obtained_at = IF(VALUES(refresh_token_enc) IS NULL, refresh_obtained_at, VALUES(refresh_obtained_at)),
                key_id = VALUES(key_id), access_expires_at = VALUES(access_expires_at),
                status = \'connected\', last_refresh_at = VALUES(last_refresh_at), version = version + 1'
        );
        $stmt->execute([
            'inst' => $installation->value,
            'client' => $clientId,
            'user' => $tokens->userId,
            'scopes' => $tokens->scope,
            'access' => $this->box->encrypt($tokens->accessToken),
            'refresh' => $tokens->refreshToken === null ? null : $this->box->encrypt($tokens->refreshToken),
            'key' => SecretBox::KEY_ID,
            'expires' => $tokens->expiresAt($now)->format('Y-m-d H:i:s.v'),
            'robtained' => $now->format('Y-m-d H:i:s.v'),
            'now' => $now->format('Y-m-d H:i:s.v'),
        ]);
    }

    public function find(InstallationId $installation): ?StoredCredential
    {
        return $this->fetch($installation, false);
    }

    public function withLockedCredential(InstallationId $installation, callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work($this->fetch($installation, true));
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function markStatus(InstallationId $installation, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE ml_credentials SET status = :status WHERE installation_id = :inst');
        $stmt->execute(['status' => $status, 'inst' => $installation->value]);
    }

    public function touchApiCall(InstallationId $installation, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE ml_credentials SET last_api_call_at = :now WHERE installation_id = :inst');
        $stmt->execute(['now' => $now->format('Y-m-d H:i:s.v'), 'inst' => $installation->value]);
    }

    private function fetch(InstallationId $installation, bool $forUpdate): ?StoredCredential
    {
        $sql = 'SELECT client_id, ml_user_id, scopes, access_token_enc, refresh_token_enc, access_expires_at,
                       refresh_obtained_at, status, last_api_call_at
                FROM ml_credentials WHERE installation_id = :inst' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['inst' => $installation->value]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        return new StoredCredential(
            clientId: (string) $row['client_id'],
            mlUserId: $row['ml_user_id'] === null ? null : (int) $row['ml_user_id'],
            scopes: $row['scopes'] === null ? null : (string) $row['scopes'],
            accessToken: $this->box->decrypt((string) $row['access_token_enc']),
            refreshToken: $row['refresh_token_enc'] === null ? null : $this->box->decrypt((string) $row['refresh_token_enc']),
            accessExpiresAt: new \DateTimeImmutable((string) $row['access_expires_at'], $utc),
            refreshObtainedAt: $row['refresh_obtained_at'] === null ? null : new \DateTimeImmutable((string) $row['refresh_obtained_at'], $utc),
            status: (string) $row['status'],
            lastApiCallAt: $row['last_api_call_at'] === null ? null : new \DateTimeImmutable((string) $row['last_api_call_at'], $utc),
        );
    }
}
