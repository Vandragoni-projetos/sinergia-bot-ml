<?php

declare(strict_types=1);

namespace Sinergia\Application\Validation;

final readonly class ValidationOutcome
{
    /**
     * @param array<string, mixed> $summary
     */
    public function __construct(
        public int $runId,
        public string $correlationId,
        public bool $succeeded,
        public ?int $httpStatus,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $evidenceFile,
        public array $summary,
    ) {
    }
}
