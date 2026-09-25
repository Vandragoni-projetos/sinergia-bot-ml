<?php

declare(strict_types=1);

namespace Sinergia\Application\Niche;

/** Escolhas privadas de UMA conta para um nicho: subnichos ativados + filtros. */
final readonly class AccountNicheSelection
{
    /** @param list<int> $subnicheIds */
    public function __construct(
        public int $nicheId,
        public array $subnicheIds,
        public NicheFilters $filters,
    ) {
    }
}
