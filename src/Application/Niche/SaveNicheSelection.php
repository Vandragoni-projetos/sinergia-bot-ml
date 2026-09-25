<?php

declare(strict_types=1);

namespace Sinergia\Application\Niche;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Port\Niche\AccountNicheStore;
use Sinergia\Application\Port\Niche\NicheCatalog;
use Sinergia\Shared\Clock\Clock;

/**
 * Salva a escolha de UM nicho para a conta autenticada. A conta vem só do TenantContext;
 * nicho e subnichos precisam estar no catálogo oferecido (nada fora dele é aceito nem gravado).
 */
final class SaveNicheSelection
{
    public function __construct(
        private readonly NicheCatalog $catalog,
        private readonly AccountNicheStore $store,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $siteId,
    ) {
    }

    /**
     * @param list<mixed>              $subnicheSlugs
     * @param array<array-key, mixed>  $filterForm
     *
     * @throws NicheSelectionRejected
     * @throws InvalidNicheFilters
     */
    public function save(TenantContext $tenant, string $nicheSlug, array $subnicheSlugs, array $filterForm): AccountNicheSelection
    {
        $niche = null;
        foreach ($this->catalog->offered($this->siteId) as $candidate) {
            if ($candidate->slug === $nicheSlug) {
                $niche = $candidate;
            }
        }
        if ($niche === null) {
            throw new NicheSelectionRejected(NicheSelectionRejected::UNKNOWN_NICHE);
        }

        $ids = [];
        foreach ($subnicheSlugs as $slug) {
            $subniche = is_string($slug) ? $niche->subnicheBySlug($slug) : null;
            if ($subniche === null) {
                throw new NicheSelectionRejected(NicheSelectionRejected::UNKNOWN_SUBNICHE);
            }
            $ids[$subniche->id] = $subniche->id;
        }
        $ids = array_values($ids);

        $filters = NicheFilters::fromForm($filterForm);
        $this->store->save($tenant->installationId, $niche->id, $ids, $filters, $tenant->userId, $this->clock->now());

        $this->logger->info('niches.selection_saved', [
            'installation_id' => $tenant->installationId->value,
            'user_id' => $tenant->userId,
            'niche' => $niche->slug,
            'subniches' => count($ids),
        ]);

        return new AccountNicheSelection($niche->id, $ids, $filters);
    }
}
