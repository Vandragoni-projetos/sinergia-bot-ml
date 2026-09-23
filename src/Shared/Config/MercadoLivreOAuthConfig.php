<?php

declare(strict_types=1);

namespace Sinergia\Shared\Config;

final readonly class MercadoLivreOAuthConfig
{
    public function __construct(
        public string $clientId,
        public SensitiveValue $clientSecret,
        public string $redirectUri,
        public bool $pkceEnabled,
    ) {
    }
}
