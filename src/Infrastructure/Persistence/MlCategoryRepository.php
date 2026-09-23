<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Port\MercadoLivre\Category;
use Sinergia\Application\Port\Persistence\CategoryCache;

/** Implementação MariaDB de CategoryCache (global, sem installation_id: dado público do site). */
final class MlCategoryRepository implements CategoryCache
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function upsert(string $siteId, Category $category, \DateTimeImmutable $fetchedAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ml_categories
                (site_id, category_id, name, parent_category_id, path_json, children_json, is_leaf, catalog_domain, total_items, fetched_at)
             VALUES
                (:site, :id, :name, :parent, :path, :children, :leaf, :domain, :total, :fetched)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), parent_category_id = VALUES(parent_category_id), path_json = VALUES(path_json),
                children_json = VALUES(children_json), is_leaf = VALUES(is_leaf), catalog_domain = VALUES(catalog_domain),
                total_items = VALUES(total_items), fetched_at = VALUES(fetched_at)'
        );
        $stmt->execute([
            'site' => $siteId,
            'id' => $category->id,
            'name' => $category->name,
            'parent' => $category->parentId(),
            'path' => json_encode($category->pathArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'children' => json_encode($category->childrenArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'leaf' => $category->isLeaf() ? 1 : 0,
            'domain' => $category->catalogDomain,
            'total' => $category->totalItems,
            'fetched' => $fetchedAt->format('Y-m-d H:i:s.v'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function find(string $siteId, string $categoryId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ml_categories WHERE site_id = :site AND category_id = :id');
        $stmt->execute(['site' => $siteId, 'id' => $categoryId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
