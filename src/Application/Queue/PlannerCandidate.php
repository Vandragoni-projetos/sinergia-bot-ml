<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

/** Candidato da última seleção DA CONTA (etapa 4), já filtrado pelo nicho a que foi atribuído. */
final readonly class PlannerCandidate
{
    public function __construct(
        public string $productId,
        public int $nicheId,
        public int $subnicheId,
        public int $sortOrder,
        public int $priceCents,
        public ?int $originalCents,
        public int $discountPct,
    ) {
    }
}
