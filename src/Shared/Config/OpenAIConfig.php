<?php

declare(strict_types=1);

namespace Sinergia\Shared\Config;

/**
 * OpenAI para a copy das ofertas (AI_COPY_PROVIDER=openai). A chave pode faltar: nesse caso o gerador falha com
 * "not_configured" e a oferta sai com a mensagem fixa v1 — a IA nunca impede a publicação.
 * A chave só existe como SensitiveValue e só sai no cabeçalho Authorization.
 */
final readonly class OpenAIConfig
{
    public function __construct(
        public string $baseUrl,
        public ?SensitiveValue $apiKey,
        public string $model,
        public int $timeoutSeconds,
    ) {
    }
}
