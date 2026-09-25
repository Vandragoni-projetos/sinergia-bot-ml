<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\WhatsApp;

/**
 * Estado da conexão informado pelo provedor NESTE momento. qrCode e pairCode são efêmeros:
 * nunca são gravados e só valem enquanto o estado for "connecting".
 */
final readonly class ConnectionSnapshot
{
    public const string DISCONNECTED = 'disconnected';
    public const string CONNECTING = 'connecting';
    public const string CONNECTED = 'connected';
    public const string HIBERNATED = 'hibernated';

    public function __construct(
        public string $state,
        public ?string $qrCode = null,
        public ?string $pairCode = null,
        public ?string $phone = null,
        public ?string $profileName = null,
    ) {
    }

    public function isConnected(): bool
    {
        return $this->state === self::CONNECTED;
    }
}
