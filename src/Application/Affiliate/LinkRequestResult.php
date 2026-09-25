<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/** request(): links já existentes + produtos que continuam pendentes (manual: aguardam lote). */
final readonly class LinkRequestResult
{
    /**
     * @param array<string, AffiliateLink> $links
     * @param list<string>                 $pending
     */
    public function __construct(
        public array $links,
        public array $pending,
    ) {
    }
}
