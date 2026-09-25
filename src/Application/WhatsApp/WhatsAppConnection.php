<?php

declare(strict_types=1);

namespace Sinergia\Application\WhatsApp;

use Sinergia\Shared\Config\SensitiveValue;

/** Conexão WhatsApp gravada de UMA conta. O token só existe decifrado na memória, como SensitiveValue. */
final readonly class WhatsAppConnection
{
    public function __construct(
        public string $instanceName,
        public ?SensitiveValue $token,
        public string $status,
        public ?string $connectMode,
        public ?\DateTimeImmutable $connectStartedAt,
        public ?\DateTimeImmutable $connectedAt,
        public ?string $phoneDisplay,
        public ?string $profileName,
        public ?string $lastErrorCode,
        public ?\DateTimeImmutable $statusCheckedAt,
    ) {
    }

    public function hasInstance(): bool
    {
        return $this->token !== null;
    }
}
