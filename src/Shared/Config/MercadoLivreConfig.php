<?php

declare(strict_types=1);

namespace Sinergia\Shared\Config;

final readonly class MercadoLivreConfig
{
    public function __construct(
        public string $siteId,
        public string $apiBaseUrl,
        public string $authBaseUrl,
        public int $timeoutSeconds,
        public string $userAgent,
    ) {
    }
}
