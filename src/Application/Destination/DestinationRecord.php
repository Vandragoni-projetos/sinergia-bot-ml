<?php

declare(strict_types=1);

namespace Sinergia\Application\Destination;

/** Destino cadastrado de UMA conta, como gravado. */
final readonly class DestinationRecord
{
    /** @param list<int> $subnicheIds */
    public function __construct(
        public int $id,
        public string $publicKey,
        public string $type,
        public string $providerRef,
        public string $name,
        public ?int $nicheId,
        public array $subnicheIds,
        public string $mode,
        public string $windowStart,
        public string $windowEnd,
        public int $intervalMinutes,
        public bool $userPaused,
        public string $status,
        public string $eligibility,
        public ?string $ineligibleReason,
        public DestinationFacts $facts,
        public ?\DateTimeImmutable $techCheckedAt,
        public bool $publicDeclared,
        public ?\DateTimeImmutable $publicDeclaredAt,
        public ?string $publicDeclaredBy,
        public ?string $publicDeclarationVersion,
        public bool $mediaDeclared,
        public ?\DateTimeImmutable $mediaDeclaredAt,
        public ?string $mediaDeclaredBy,
        public ?string $mediaDeclarationVersion,
        public ?\DateTimeImmutable $lastTestSentAt,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->nicheId !== null && $this->subnicheIds !== [];
    }

    public function eligibility(): Eligibility
    {
        return DestinationEligibility::evaluate($this->facts, $this->publicDeclared, $this->mediaDeclared);
    }
}
