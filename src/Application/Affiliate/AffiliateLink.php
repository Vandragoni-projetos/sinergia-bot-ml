<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/** Link ativo da biblioteca DA CONTA. */
final readonly class AffiliateLink
{
    public function __construct(
        public int $id,
        public string $productId,
        public string $affiliateUrl,
        public string $source,
        public \DateTimeImmutable $confirmedAt,
    ) {
    }
}
