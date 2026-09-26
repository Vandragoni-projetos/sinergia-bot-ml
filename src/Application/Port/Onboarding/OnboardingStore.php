<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Onboarding;

use Sinergia\Application\Onboarding\OnboardingFacts;
use Sinergia\Domain\Installation\InstallationId;

/** Fatos do onboarding de UMA conta (sempre filtrados por installation_id). */
interface OnboardingStore
{
    public function facts(InstallationId $installation): OnboardingFacts;

    /** Ativa o bot e registra a 1ª conclusão do onboarding (só chamado depois do checklist completo). */
    public function activate(InstallationId $installation, int $userId, \DateTimeImmutable $now): void;
}
