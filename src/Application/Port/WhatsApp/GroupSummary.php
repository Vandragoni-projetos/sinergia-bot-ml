<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\WhatsApp;

/**
 * Fatos técnicos de um grupo, como a Uazapi os informa. null = o provedor NÃO informou (desconhecido);
 * nunca é convertido em "sim" ou "não".
 */
final readonly class GroupSummary
{
    public function __construct(
        public string $jid,
        public string $name,
        public ?bool $ownerIsAdmin,
        public ?bool $joinApprovalRequired,
        public ?bool $announceOnly,
        public ?int $participants,
        public ?bool $isCommunity = null,
        public ?bool $hasInviteLink = null,
    ) {
    }
}
