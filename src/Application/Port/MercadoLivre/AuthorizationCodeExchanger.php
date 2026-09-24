<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

use Sinergia\Shared\Config\SensitiveValue;

/** Troca do authorization code por tokens (endpoint oficial /oauth/token). */
interface AuthorizationCodeExchanger
{
    /** @throws MercadoLivreFailure */
    public function exchangeCode(SensitiveValue $code, ?SensitiveValue $codeVerifier): TokenSet;

    public function clientId(): string;
}
