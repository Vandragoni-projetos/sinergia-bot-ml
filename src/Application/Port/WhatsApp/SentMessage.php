<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\WhatsApp;

final readonly class SentMessage
{
    public function __construct(public ?string $providerMessageId)
    {
    }
}
