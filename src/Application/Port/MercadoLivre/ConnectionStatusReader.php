<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

use Sinergia\Domain\Installation\InstallationId;

/** Leituras da tela Conexões. Sempre escopadas por conta; nunca decifram tokens. */
interface ConnectionStatusReader
{
    public function summary(InstallationId $installation): ?ConnectionSummary;

    public function hasPendingPanelAuthorization(InstallationId $installation, \DateTimeImmutable $now): bool;

    public function affiliateMode(InstallationId $installation): string;
}
