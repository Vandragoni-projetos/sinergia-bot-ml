<?php

declare(strict_types=1);

namespace Sinergia\Shared\Clock;

interface Clock
{
    /** Sempre em UTC. */
    public function now(): \DateTimeImmutable;
}
