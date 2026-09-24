<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Port\MercadoLivre\OAuthStateRejected;
use Sinergia\Application\Port\MercadoLivre\OAuthStateStore;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Shared\Config\SensitiveValue;

/** Estado OAuth de uso único: só o hash do state é guardado; o code_verifier fica cifrado. */
final class OAuthStateRepository implements OAuthStateStore
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly SecretBox $box,
    ) {
    }

    public function create(
        InstallationId $installation,
        SensitiveValue $state,
        ?SensitiveValue $codeVerifier,
        \DateTimeImmutable $expiresAt,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ml_oauth_states (installation_id, state_hash, code_verifier_enc, expires_at)
             VALUES (:inst, :hash, :verifier, :expires)'
        );
        $stmt->execute([
            'inst' => $installation->value,
            'hash' => hash('sha256', $state->reveal()),
            'verifier' => $codeVerifier === null ? null : $this->box->encrypt($codeVerifier),
            'expires' => $expiresAt->format('Y-m-d H:i:s.v'),
        ]);
    }

    /**
     * Consome o state (apaga a linha). Retorna o code_verifier (ou null se PKCE desligado).
     *
     * @return array{verifier: ?SensitiveValue}
     */
    public function consume(InstallationId $installation, SensitiveValue $state, \DateTimeImmutable $now): array
    {
        $hash = hash('sha256', $state->reveal());
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, code_verifier_enc, expires_at FROM ml_oauth_states
                 WHERE installation_id = :inst AND state_hash = :hash FOR UPDATE'
            );
            $stmt->execute(['inst' => $installation->value, 'hash' => $hash]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                throw OAuthStateRejected::unknown();
            }
            $this->pdo->prepare('DELETE FROM ml_oauth_states WHERE id = :id')->execute(['id' => $row['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $expires = new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC'));
        if ($expires < $now) {
            throw OAuthStateRejected::expired();
        }

        return [
            'verifier' => $row['code_verifier_enc'] === null ? null : $this->box->decrypt((string) $row['code_verifier_enc']),
        ];
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM ml_oauth_states WHERE expires_at < :now');
        $stmt->execute(['now' => $now->format('Y-m-d H:i:s.v')]);

        return $stmt->rowCount();
    }
}
