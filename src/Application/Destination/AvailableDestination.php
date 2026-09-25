<?php

declare(strict_types=1);

namespace Sinergia\Application\Destination;

/** Grupo ou canal encontrado no WhatsApp DA CONTA na última sincronização. pickKey é aleatório (vai para o formulário). */
final readonly class AvailableDestination
{
    public function __construct(
        public string $pickKey,
        public string $type,
        public string $providerRef,
        public string $name,
        public ?bool $weAreAdmin,
        public ?bool $joinApproval,
        public ?bool $announceOnly,
        public ?bool $isCommunity,
        public ?int $participants,
        public bool $registered = false,
    ) {
    }
}
