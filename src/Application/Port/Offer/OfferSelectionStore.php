<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Offer;

use Sinergia\Application\Offer\OfferCandidate;
use Sinergia\Application\Offer\SelectionTarget;
use Sinergia\Domain\Installation\InstallationId;

/** Dados PRIVADOS da seleção: alvos (escolhas da conta × curadoria), execuções e candidatos. */
interface OfferSelectionStore
{
    /**
     * Categorias APROVADAS dos subnichos ativados pela conta, com os filtros do nicho.
     *
     * @return list<SelectionTarget>
     */
    public function targets(InstallationId $installation, string $siteId): array;

    public function startRun(InstallationId $installation, \DateTimeImmutable $now): int;

    /**
     * @param array<string, mixed> $stats
     * @param list<OfferCandidate> $candidates
     */
    public function finishRun(
        InstallationId $installation,
        int $runId,
        string $status,
        int $apiCalls,
        array $stats,
        array $candidates,
        \DateTimeImmutable $now,
    ): void;
}
