<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Offer;

use Sinergia\Domain\Installation\InstallationId;

/** Cria a fonte de catálogo que usa o token Mercado Livre DA PRÓPRIA conta (nunca o de outra). */
interface CatalogSourceFactory
{
    public function forInstallation(InstallationId $installation): CatalogSource;
}
