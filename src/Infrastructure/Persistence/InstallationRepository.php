<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Domain\Installation\Installation;
use Sinergia\Domain\Installation\InstallationId;

final class InstallationRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function findBySlug(string $slug): ?Installation
    {
        $stmt = $this->pdo->prepare('SELECT id, slug, name, status, site_id, timezone FROM installations WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** Cria a instalação se não existir (idempotente). */
    public function ensure(string $slug, string $name, string $siteId): Installation
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO installations (slug, name, site_id) VALUES (:slug, :name, :site)
             ON DUPLICATE KEY UPDATE slug = slug'
        );
        $stmt->execute(['slug' => $slug, 'name' => $name, 'site' => $siteId]);

        return $this->findBySlug($slug) ?? throw new \RuntimeException('Instalação não encontrada após criação.');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Installation
    {
        return new Installation(
            id: new InstallationId((int) $row['id']),
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            status: (string) $row['status'],
            siteId: (string) $row['site_id'],
            timezone: (string) $row['timezone'],
        );
    }
}
