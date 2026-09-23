<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

use Sinergia\Domain\Installation\InstallationId;

/**
 * Armazenamento da conexão OAuth de uma instalação. A implementação decide COMO
 * persistir e cifrar; quem renova tokens só conhece este contrato.
 */
interface CredentialStore
{
    public function find(InstallationId $installation): ?StoredCredential;

    public function save(InstallationId $installation, string $clientId, TokenSet $tokens, \DateTimeImmutable $now): void;

    public function markStatus(InstallationId $installation, string $status): void;

    public function touchApiCall(InstallationId $installation, \DateTimeImmutable $now): void;

    /**
     * Executa $work com a credencial TRAVADA (exclusão mútua entre processos) e dentro de uma
     * unidade atômica: confirma se $work retornar, desfaz se lançar. O refresh_token do
     * Mercado Livre é de uso único, então a renovação sempre passa por aqui.
     *
     * @template T
     * @param callable(?StoredCredential): T $work
     * @return T
     */
    public function withLockedCredential(InstallationId $installation, callable $work): mixed;
}
