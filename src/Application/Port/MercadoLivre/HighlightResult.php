<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

final readonly class HighlightResult
{
    /**
     * @param list<HighlightEntry> $entries
     * @param list<string> $warnings divergências observadas em relação à documentação
     */
    public function __construct(
        public string $siteId,
        public string $categoryId,
        public ?string $highlightType,
        public ?string $criteria,
        public ?string $queryId,
        public array $entries,
        public array $warnings,
        public ResponseMeta $meta,
    ) {
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return array<string, int> */
    public function countByType(): array
    {
        $counts = [];
        foreach ($this->entries as $entry) {
            $key = $entry->type ?? '(sem type)';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }
}
