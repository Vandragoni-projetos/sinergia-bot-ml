<?php

declare(strict_types=1);

namespace Sinergia\Integration\AI;

use Sinergia\Application\Port\Copy\OfferCopy;
use Sinergia\Application\Port\Copy\OfferCopyGenerator;
use Sinergia\Application\Port\Copy\OfferCopyRequest;

/** Sem IA (AI_COPY_PROVIDER=none, o padrão): nenhuma chamada externa; a oferta sai com a mensagem fixa v1. */
final class FixedOfferCopyGenerator implements OfferCopyGenerator
{
    public function provider(): string
    {
        return 'none';
    }

    public function model(): ?string
    {
        return null;
    }

    public function promptVersion(): ?string
    {
        return null;
    }

    public function generate(OfferCopyRequest $request): ?OfferCopy
    {
        return null;
    }
}
