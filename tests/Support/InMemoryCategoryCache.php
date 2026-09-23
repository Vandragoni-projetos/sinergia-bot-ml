<?php

declare(strict_types=1);

namespace Sinergia\Tests\Support;

use Sinergia\Application\Port\MercadoLivre\Category;
use Sinergia\Application\Port\Persistence\CategoryCache;

/** CategoryCache em memória (testes sem banco). Também expõe find(), usado pela CLI. */
final class InMemoryCategoryCache implements CategoryCache
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function upsert(string $siteId, Category $category, \DateTimeImmutable $fetchedAt): void
    {
        $this->rows[$siteId . ':' . $category->id] = ['category_id' => $category->id, 'name' => $category->name];
    }

    /** @return array<string, mixed>|null */
    public function find(string $siteId, string $categoryId): ?array
    {
        return $this->rows[$siteId . ':' . $categoryId] ?? null;
    }
}
