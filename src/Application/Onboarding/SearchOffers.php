<?php

declare(strict_types=1);

namespace Sinergia\Application\Onboarding;

use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Offer\SelectionReport;
use Sinergia\Application\Offer\SelectOffers;
use Sinergia\Application\Port\Onboarding\OnboardingStore;
use Sinergia\Shared\Clock\Clock;

/**
 * "Buscar ofertas" pelo painel (sem terminal): roda o seletor da etapa 4 para a conta autenticada.
 * Exige Mercado Livre conectado e ao menos um subnicho; no máximo uma busca a cada MIN_INTERVAL_SECONDS.
 */
final class SearchOffers
{
    public const int MIN_INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly OnboardingStore $store,
        private readonly SelectOffers $selector,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws ActivationBlocked quando falta Mercado Livre ou nicho
     * @throws OfferSearchTooSoon
     */
    public function search(TenantContext $tenant): SelectionReport
    {
        $facts = $this->store->facts($tenant->installationId);
        if (!$facts->mercadoLivreConnected) {
            throw new ActivationBlocked('mercado_livre');
        }
        if ($facts->activeSubniches === 0) {
            throw new ActivationBlocked('nichos');
        }
        if ($facts->lastOfferSearch !== null && $this->clock->now()->getTimestamp() - $facts->lastOfferSearch->getTimestamp() < self::MIN_INTERVAL_SECONDS) {
            throw new OfferSearchTooSoon();
        }

        return $this->selector->run($tenant->installationId);
    }
}
