<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\WhatsApp;

/** Página de grupos da instância (usada a partir da etapa 6). */
final readonly class GroupPage
{
    /** @param list<GroupSummary> $groups */
    public function __construct(
        public array $groups,
        public ?int $total,
    ) {
    }
}
