<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Port\Affiliate\MediaDeclarationStore;
use Sinergia\Domain\Installation\InstallationId;

/** Declaração de Mídias: histórico por conta e usuário; a vigente é a última não revogada. */
final class MediaDeclarationRepository implements MediaDeclarationStore
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function current(InstallationId $installation): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.user_id, u.name AS user_name, d.declaration_version, d.declared_at
             FROM affiliate_media_declarations d
             JOIN users u ON u.installation_id = d.installation_id AND u.id = d.user_id
             WHERE d.installation_id = :inst AND d.revoked_at IS NULL
             ORDER BY d.declared_at DESC, d.id DESC LIMIT 1'
        );
        $stmt->execute(['inst' => $installation->value]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'user_id' => (int) $row['user_id'],
            'user_name' => (string) $row['user_name'],
            'version' => (string) $row['declaration_version'],
            'declared_at' => new \DateTimeImmutable((string) $row['declared_at'], new \DateTimeZone('UTC')),
        ];
    }

    public function declare(InstallationId $installation, int $userId, string $version, \DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->revokeOpen($installation, $userId, $now);
            $stmt = $this->pdo->prepare(
                'INSERT INTO affiliate_media_declarations (installation_id, user_id, declaration_version, declared_at)
                 VALUES (:inst, :user, :version, :at)'
            );
            $stmt->execute(['inst' => $installation->value, 'user' => $userId, 'version' => $version, 'at' => $now->format('Y-m-d H:i:s.v')]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function revoke(InstallationId $installation, int $userId, \DateTimeImmutable $now): void
    {
        $this->revokeOpen($installation, $userId, $now);
    }

    private function revokeOpen(InstallationId $installation, int $userId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE affiliate_media_declarations SET revoked_at = :at, revoked_by_user_id = :user
             WHERE installation_id = :inst AND revoked_at IS NULL'
        );
        $stmt->execute(['at' => $now->format('Y-m-d H:i:s.v'), 'user' => $userId, 'inst' => $installation->value]);
    }
}
