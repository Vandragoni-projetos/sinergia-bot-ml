<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

/**
 * Leitura da árvore oficial de categorias. O modo de autenticação é sempre explícito:
 * a validação começa sem token e só usa OAuth se a resposta oficial exigir.
 * Falhas implementam MercadoLivreFailure.
 */
interface CategoryReader
{
    public function siteRoots(string $siteId, AuthMode $auth): SiteRoots;

    public function category(string $siteId, string $categoryId, AuthMode $auth): Category;
}
