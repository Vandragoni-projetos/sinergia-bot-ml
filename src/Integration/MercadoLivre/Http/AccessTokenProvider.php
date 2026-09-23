<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Http;

use Sinergia\Shared\Config\SensitiveValue;

interface AccessTokenProvider
{
    /** Token válido para uso imediato (renovado se necessário). */
    public function accessToken(): SensitiveValue;
}
