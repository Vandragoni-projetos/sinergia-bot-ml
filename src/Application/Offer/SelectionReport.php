<?php

declare(strict_types=1);

namespace Sinergia\Application\Offer;

final readonly class SelectionReport
{
    public const string COMPLETED = 'completed';
    public const string PARTIAL = 'partial';
    public const string AUTH_FAILED = 'auth_failed';
    public const string NO_SELECTION = 'no_selection';

    /**
     * @param array<string, mixed> $stats
     * @param list<OfferCandidate> $candidates
     */
    public function __construct(
        public int $runId,
        public string $status,
        public int $apiCalls,
        public array $stats,
        public array $candidates,
    ) {
    }
}
