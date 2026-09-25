<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\WhatsApp;

use Sinergia\Shared\Config\SensitiveValue;

/** Instância recém-criada no provedor: o token só existe como SensitiveValue e é gravado cifrado. */
final readonly class ProviderInstance
{
    public function __construct(
        public ?string $providerId,
        public string $name,
        public SensitiveValue $token,
    ) {
    }
}
