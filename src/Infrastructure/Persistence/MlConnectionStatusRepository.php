<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Port\MercadoLivre\ConnectionStatusReader;
use Sinergia\Application\Port\MercadoLivre\ConnectionSummary;
use Sinergia\Domain\Installation\InstallationId;

/** Situação da conexão para a tela Conexões. Não lê nem decifra colunas de token. */
final class MlConnectionStatusRepository implements ConnectionStatusReader
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function summary(InstallationId $installation): ?ConnectionSummary
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, ml_user_id, access_expires_at, (refresh_token_enc IS NOT NULL) AS has_refresh,
                    created_at, last_refresh_at
             FROM ml_credentials WHERE installation_id = :inst'
        );
        $stmt->execute(['inst' => $installation->value]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        return new ConnectionSummary(
            status: (string) $row['status'],
            mlUserId: $row['ml_user_id'] === null ? null : (int) $row['ml_user_id'],
            accessExpiresAt: new \DateTimeImmutable((string) $row['access_expires_at'], $utc),
            hasRefreshToken: (bool) $row['has_refresh'],
            connectedAt: new \DateTimeImmutable((string) $row['created_at'], $utc),
            lastRefreshAt: $row['last_refresh_at'] === null ? null : new \DateTimeImmutable((string) $row['last_refresh_at'], $utc),
        );
    }

    public function hasPendingPanelAuthorization(InstallationId $installation, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM ml_oauth_states
             WHERE installation_id = :inst AND origin = 'panel' AND expires_at > :now LIMIT 1"
        );
        $stmt->execute(['inst' => $installation->value, 'now' => $now->format('Y-m-d H:i:s.v')]);

        return $stmt->fetchColumn() !== false;
    }

    public function affiliateMode(InstallationId $installation): string
    {
        $stmt = $this->pdo->prepare('SELECT affiliate_mode FROM installations WHERE id = :inst');
        $stmt->execute(['inst' => $installation->value]);

        return (string) ($stmt->fetchColumn() ?: 'manual_batch');
    }
}
