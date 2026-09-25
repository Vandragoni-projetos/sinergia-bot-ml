<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\WhatsApp;

/**
 * Canal (newsletter) seguido pela conta conectada. weAreAdmin: true = dono/admin, false = só segue,
 * null = o provedor não informou o papel (schema de /newsletter/list não é documentado).
 */
final readonly class ChannelSummary
{
    public function __construct(
        public string $jid,
        public string $name,
        public ?bool $weAreAdmin,
    ) {
    }
}
