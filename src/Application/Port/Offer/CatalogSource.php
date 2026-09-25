<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Offer;

use Sinergia\Application\Offer\CatalogProduct;
use Sinergia\Application\Offer\ProductOffer;
use Sinergia\Application\Port\MercadoLivre\HighlightEntry;
use Sinergia\Application\Port\MercadoLivre\MercadoLivreFailure;

/**
 * Leitura OFICIAL do catálogo do Mercado Livre, autenticada com a credencial de UMA conta.
 * Só três chamadas: /highlights/{site}/category/{id}, /products/{id} e /products/{id}/items.
 */
interface CatalogSource
{
    /**
     * @return list<HighlightEntry> ordenadas por posição
     *
     * @throws MercadoLivreFailure
     */
    public function ranking(string $siteId, string $categoryId): array;

    /** @throws MercadoLivreFailure */
    public function product(string $productId): CatalogProduct;

    /**
     * @return array{offers: list<ProductOffer>, total: ?int}
     *
     * @throws MercadoLivreFailure
     */
    public function offers(string $productId): array;
}
