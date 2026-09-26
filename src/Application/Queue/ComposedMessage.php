<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

/**
 * Legenda pronta + metadados SEGUROS para o message_json: formato, provedor, modelo, versão do prompt e se houve
 * fallback. Nunca contém chave, prompt, resposta bruta da IA ou motivo detalhado.
 */
final readonly class ComposedMessage
{
    /** @param array{provider: string, model: ?string, prompt_version: ?string, fallback: bool} $copy */
    public function __construct(
        public string $caption,
        public string $format,
        public array $copy,
    ) {
    }
}
