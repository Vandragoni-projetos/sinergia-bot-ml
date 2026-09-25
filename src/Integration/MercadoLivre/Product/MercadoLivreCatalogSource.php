<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Product;

use Sinergia\Application\Offer\CatalogProduct;
use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Application\Port\Offer\CatalogSource;
use Sinergia\Integration\MercadoLivre\Highlight\HighlightService;

final class MercadoLivreCatalogSource implements CatalogSource
{
    public function __construct(
        private readonly HighlightService $highlights,
        private readonly ProductService $products,
    ) {
    }

    public function ranking(string $siteId, string $categoryId): array
    {
        return $this->highlights->topByCategory($siteId, $categoryId, AuthMode::Required)->entries;
    }

    public function product(string $productId): CatalogProduct
    {
        return $this->products->product($productId);
    }

    public function offers(string $productId): array
    {
        return $this->products->offers($productId);
    }
}
