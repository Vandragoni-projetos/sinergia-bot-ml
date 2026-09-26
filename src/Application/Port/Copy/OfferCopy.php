<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Copy;

/**
 * Os dois pequenos campos de copy. Vindo do gerador é só um RASCUNHO: a mensagem só usa o que o CopyValidator
 * devolve (normalizado e aprovado).
 */
final readonly class OfferCopy
{
    public function __construct(
        public string $hook,
        public string $callToAction,
    ) {
    }
}
