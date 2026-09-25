<?php

declare(strict_types=1);

namespace Sinergia\Application\Destination;

final readonly class Eligibility
{
    public const string CHANNEL_PUBLIC = 'channel_public';
    public const string GROUP_DECLARED_PUBLIC = 'group_declared_public';
    public const string INELIGIBLE = 'ineligible';

    /**
     * @param ?string $reason            motivo exibido quando INELIGIBLE (técnico ou de declaração)
     * @param ?string $techBlockingReason motivo TÉCNICO (gravado em tech_blocking_reason); declaração não entra aqui
     */
    public function __construct(
        public string $label,
        public ?string $reason,
        public ?string $techBlockingReason,
    ) {
    }

    public function isEligible(): bool
    {
        return $this->label !== self::INELIGIBLE;
    }
}
