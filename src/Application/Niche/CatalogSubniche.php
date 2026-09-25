<?php

declare(strict_types=1);

namespace Sinergia\Application\Niche;

final readonly class CatalogSubniche
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
    ) {
    }
}
