<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

/**
 * Ranking oficial de mais vendidos por categoria-folha. Modo de autenticação explícito.
 * Falhas implementam MercadoLivreFailure.
 */
interface HighlightReader
{
    public function topByCategory(
        string $siteId,
        string $categoryId,
        AuthMode $auth,
        ?string $attribute = null,
        ?string $attributeValue = null,
    ): HighlightResult;
}
