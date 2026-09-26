<?php

declare(strict_types=1);

namespace Sinergia\Application\Onboarding;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Port\Onboarding\OnboardingStore;
use Sinergia\Shared\Clock\Clock;

/**
 * ÚNICO caminho para ativar o bot (tela Primeiros passos e botão Ativar da Fila). O checklist é recalculado no
 * servidor, a partir dos dados da conta, no momento do clique. Nunca ativa sozinho.
 */
final class ActivateBot
{
    public function __construct(
        private readonly OnboardingStore $store,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function checklist(TenantContext $tenant): OnboardingChecklist
    {
        return new OnboardingChecklist($this->store->facts($tenant->installationId));
    }

    /** @throws ActivationBlocked com o primeiro passo pendente */
    public function activate(TenantContext $tenant): void
    {
        $pending = $this->checklist($tenant)->firstPending();
        if ($pending !== null) {
            $this->logger->info('onboarding.activation_blocked', ['installation_id' => $tenant->installationId->value, 'pending' => $pending->key]);
            throw new ActivationBlocked($pending->key);
        }
        $this->store->activate($tenant->installationId, $tenant->userId, $this->clock->now());
        $this->logger->info('onboarding.bot_activated', ['installation_id' => $tenant->installationId->value, 'user_id' => $tenant->userId]);
    }
}
