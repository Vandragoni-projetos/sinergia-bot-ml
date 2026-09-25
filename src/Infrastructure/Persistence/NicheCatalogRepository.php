<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Niche\CatalogNiche;
use Sinergia\Application\Niche\CatalogSubniche;
use Sinergia\Application\Port\Niche\NicheCatalog;

/** Leitura do catálogo global. Só oferece subnichos com categoria aprovada; nunca devolve IDs do ML. */
final class NicheCatalogRepository implements NicheCatalog
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function offered(string $siteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.id AS niche_id, n.slug AS niche_slug, n.name AS niche_name, s.id, s.slug, s.name
             FROM niches n
             JOIN subniches s ON s.niche_id = n.id
             WHERE n.active = 1 AND s.active = 1
               AND EXISTS (
                   SELECT 1 FROM subniche_categories c
                   WHERE c.subniche_id = s.id AND c.site_id = :site AND c.status = \'approved\'
               )
             ORDER BY n.sort, n.name, s.sort, s.name'
        );
        $stmt->execute(['site' => $siteId]);

        $niches = [];
        $subniches = [];
        foreach ($stmt->fetchAll() as $row) {
            $nicheId = (int) $row['niche_id'];
            $niches[$nicheId] ??= [(string) $row['niche_slug'], (string) $row['niche_name']];
            $subniches[$nicheId][] = new CatalogSubniche((int) $row['id'], (string) $row['slug'], (string) $row['name']);
        }

        $result = [];
        foreach ($niches as $id => [$slug, $name]) {
            $result[] = new CatalogNiche($id, $slug, $name, $subniches[$id]);
        }

        return $result;
    }
}
