<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Niche;

use Sinergia\Application\Niche\CatalogNiche;

/** Catálogo GLOBAL (somente leitura para o painel): administrado pela Sinergia via migrations. */
interface NicheCatalog
{
    /**
     * Nichos e subnichos ativos com pelo menos uma categoria APROVADA no site.
     *
     * @return list<CatalogNiche>
     */
    public function offered(string $siteId): array;
}
