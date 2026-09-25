<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

/** Destino ATIVO (elegível, configurado e não pausado) de uma conta, com o necessário para planejar. */
final readonly class PlannerDestination
{
    /** @param list<int> $subnicheIds */
    public function __construct(
        public int $id,
        public string $mode,
        public string $windowStart,
        public string $windowEnd,
        public int $intervalMinutes,
        public int $nicheId,
        public array $subnicheIds,
        public ?\DateTimeImmutable $lastSentAt,
        public ?\DateTimeImmutable $lastPlannedAt,
        public ?int $lastSubnicheId,
    ) {
    }
}
