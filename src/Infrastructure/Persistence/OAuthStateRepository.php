<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Port\MercadoLivre\OAuthStateRejected;
use Sinergia\Application\Port\MercadoLivre\OAuthStateStore;
use Sinergia\Application\Port\MercadoLivre\PendingOAuthState;
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
        string $origin = PendingOAuthState::ORIGIN_CLI,
        ?int $startedByUserId = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ml_oauth_states (installation_id, state_hash, code_verifier_enc, expires_at, origin, started_by_user_id)
             VALUES (:inst, :hash, :verifier, :expires, :origin, :user)'
        );
        $stmt->execute([
            'inst' => $installation->value,
            'hash' => hash('sha256', $state->reveal()),
            'verifier' => $codeVerifier === null ? null : $this->box->encrypt($codeVerifier),
            'expires' => $expiresAt->format('Y-m-d H:i:s.v'),
            'origin' => $origin,
            'user' => $startedByUserId,
        ]);
    }

    /**
     * Consome o state de uma conta conhecida (apaga a linha). Retorna o code_verifier (ou null).
     *
     * @return array{verifier: ?SensitiveValue}
     */
    public function consume(InstallationId $installation, SensitiveValue $state, \DateTimeImmutable $now): array
    {
        $row = $this->take(
            'WHERE installation_id = :inst AND state_hash = :hash',
            ['inst' => $installation->value, 'hash' => hash('sha256', $state->reveal())],
            $now,
        );

        return ['verifier' => $this->verifier($row)];
    }

    public function consumeByState(SensitiveValue $state, \DateTimeImmutable $now): PendingOAuthState
    {
        $row = $this->take('WHERE state_hash = :hash', ['hash' => hash('sha256', $state->reveal())], $now);

        return new PendingOAuthState(
            new InstallationId((int) $row['installation_id']),
            (string) $row['origin'],
            $row['started_by_user_id'] === null ? null : (int) $row['started_by_user_id'],
            $this->verifier($row),
        );
    }

    /**
     * Uso único: apaga a linha na mesma transação em que a lê; expirado também é apagado.
     *
     * @param array<string, int|string> $params
     *
     * @return array<string, mixed>
     */
    private function take(string $where, array $params, \DateTimeImmutable $now): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, installation_id, code_verifier_enc, expires_at, origin, started_by_user_id
                 FROM ml_oauth_states ' . $where . ' FOR UPDATE'
            );
            $stmt->execute($params);
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

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function verifier(array $row): ?SensitiveValue
    {
        return $row['code_verifier_enc'] === null ? null : $this->box->decrypt((string) $row['code_verifier_enc']);
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM ml_oauth_states WHERE expires_at < :now');
        $stmt->execute(['now' => $now->format('Y-m-d H:i:s.v')]);

        return $stmt->rowCount();
    }
}
