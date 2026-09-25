<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/** Produto que precisa de link: identidade de catálogo + URL original (entregue ao Gerador sem alteração). */
final readonly class ProductToLink
{
    public function __construct(
        public string $productId,
        public string $originalUrl,
        public string $name,
    ) {
    }
}
