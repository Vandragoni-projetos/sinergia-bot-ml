<?php

declare(strict_types=1);

namespace Sinergia\Application\Destination;

/**
 * Fatos TÉCNICOS de um destino, lidos do WhatsApp via provedor. Separados da declaração do cliente.
 * null = desconhecido (o provedor não informou); nunca significa "sim" nem "não".
 */
final readonly class DestinationFacts
{
    public function __construct(
        public bool $isChannel,
        public bool $presentInWhatsApp,
        public ?bool $weAreAdmin,
        public ?bool $hasInviteLink,
        public ?bool $joinApproval,
        public ?bool $announceOnly,
        public ?bool $isCommunity,
    ) {
    }
}
