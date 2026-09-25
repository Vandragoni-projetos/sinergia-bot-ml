<?php

declare(strict_types=1);

namespace Sinergia\Application\Offer;

use Sinergia\Application\Niche\NicheFilters;

/** Uma categoria aprovada a consultar para a conta, com o subnicho de origem e os filtros do nicho. */
final readonly class SelectionTarget
{
    /** @param list<string> $expectedDomains */
    public function __construct(
        public int $nicheId,
        public int $nicheSort,
        public int $subnicheId,
        public int $subnicheSort,
        public string $categoryId,
        public array $expectedDomains,
        public NicheFilters $filters,
    ) {
    }

    /**
     * Chave de desempate determinística entre ocorrências com a mesma posição no ranking.
     *
     * @return list<int|string>
     */
    public function orderKey(): array
    {
        return [$this->nicheSort, $this->nicheId, $this->subnicheSort, $this->subnicheId, $this->categoryId];
    }
}
