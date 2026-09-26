<?php

declare(strict_types=1);

namespace Sinergia\Shared\Config;

/**
 * Servidor Evolution API (self-hosted) usado pelo SINERGIA. Só a URL base: a chave global da Evolution NÃO existe
 * no BotML (opção C). Cada conta recebe a credencial da PRÓPRIA instância, gravada cifrada no banco.
 */
final readonly class EvolutionConfig
{
    public function __construct(
        public string $baseUrl,
        public int $timeoutSeconds,
    ) {
    }
}
