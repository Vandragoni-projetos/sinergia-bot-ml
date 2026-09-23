<?php

declare(strict_types=1);

namespace Sinergia\Shared\Clock;

/** Relógio controlável para testes. */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $isoUtc = '2026-01-01T00:00:00Z')
    {
        $this->now = new \DateTimeImmutable($isoUtc, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->add(new \DateInterval($interval));
    }
}
