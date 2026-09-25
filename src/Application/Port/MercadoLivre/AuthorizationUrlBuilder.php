<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

use Sinergia\Shared\Config\SensitiveValue;

/** Monta a URL oficial de autorização do Mercado Livre (o login é sempre feito pelo usuário). */
interface AuthorizationUrlBuilder
{
    public function authorizationUrl(SensitiveValue $state, ?SensitiveValue $codeVerifier): string;

    public function pkceEnabled(): bool;
}
