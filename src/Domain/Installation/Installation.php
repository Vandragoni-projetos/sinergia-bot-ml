<?php

declare(strict_types=1);

namespace Sinergia\Domain\Installation;

final readonly class Installation
{
    public function __construct(
        public InstallationId $id,
        public string $slug,
        public string $name,
        public string $status,
        public string $siteId,
        public string $timezone,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
