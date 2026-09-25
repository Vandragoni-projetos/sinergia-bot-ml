<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Niche;

use Sinergia\Application\Niche\AccountNicheSelection;
use Sinergia\Application\Niche\NicheFilters;
use Sinergia\Domain\Installation\InstallationId;

/** Preferências privadas de cada conta; toda leitura/escrita é restrita ao installation_id recebido. */
interface AccountNicheStore
{
    /** @return array<int, AccountNicheSelection> por niche_id */
    public function selections(InstallationId $installation): array;

    /**
     * Substitui, de forma atômica, os subnichos ativados e os filtros da conta para um nicho.
     *
     * @param list<int> $subnicheIds
     */
    public function save(
        InstallationId $installation,
        int $nicheId,
        array $subnicheIds,
        NicheFilters $filters,
        int $userId,
        \DateTimeImmutable $now,
    ): void;
}
