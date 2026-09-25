<?php

declare(strict_types=1);

namespace Sinergia\Application\Auth;

use Sinergia\Domain\Installation\InstallationId;

/**
 * Conta (installation) e usuário autenticados da requisição atual.
 * Só nasce de uma sessão válida; toda leitura/escrita de dados do painel usa este installationId.
 */
final readonly class TenantContext
{
    public function __construct(
        public InstallationId $installationId,
        public string $installationName,
        public int $userId,
        public string $userName,
        public string $userEmail,
    ) {
    }
}
