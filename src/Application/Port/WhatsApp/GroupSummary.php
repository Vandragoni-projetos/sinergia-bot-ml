<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\WhatsApp;

/** Resumo de um grupo com os sinais técnicos que a etapa 6 usa na elegibilidade. */
final readonly class GroupSummary
{
    public function __construct(
        public string $jid,
        public string $name,
        public ?bool $ownerIsAdmin,
        public ?bool $joinApprovalRequired,
        public ?bool $announceOnly,
        public ?int $participants,
    ) {
    }
}
