<?php

declare(strict_types=1);

namespace Sinergia\Shared\Config;

/** Servidor Uazapi (uazapiGO) do SINERGIA: URL base https e admintoken (somente backend, nunca exposto). */
final readonly class UazapiConfig
{
    public function __construct(
        public string $baseUrl,
        public SensitiveValue $adminToken,
        public int $timeoutSeconds,
    ) {
    }
}
