<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Queue;

use Sinergia\Domain\Installation\InstallationId;

/** Decisões do cliente sobre itens DA PRÓPRIA conta (chave aleatória do item, nunca id interno). */
interface QueueDecisions
{
    /** pending_approval → scheduled. false = item não encontrado nesta conta ou em outro estado. */
    public function approve(InstallationId $installation, string $key, int $userId, \DateTimeImmutable $now): bool;

    /** pending_approval | scheduled | awaiting_affiliate_link → skipped (o produto não volta para este destino). */
    public function skip(InstallationId $installation, string $key, int $userId, \DateTimeImmutable $now): bool;
}
