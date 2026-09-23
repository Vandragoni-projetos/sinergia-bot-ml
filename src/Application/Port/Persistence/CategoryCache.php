<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Persistence;

use Sinergia\Application\Port\MercadoLivre\Category;

/** Cache da árvore oficial de categorias (dado público de referência do site). */
interface CategoryCache
{
    public function upsert(string $siteId, Category $category, \DateTimeImmutable $fetchedAt): void;
}
