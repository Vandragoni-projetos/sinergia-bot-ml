<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Config\SensitiveValue;

/** Estados OAuth pendentes (uso único, com expiração) de uma instalação. */
interface OAuthStateStore
{
    /**
     * Consome o state: some do armazenamento mesmo quando está expirado.
     *
     * @return array{verifier: ?SensitiveValue} code_verifier PKCE (null se PKCE desligado)
     *
     * @throws OAuthStateRejected state desconhecido, já utilizado ou expirado
     */
    public function consume(InstallationId $installation, SensitiveValue $state, \DateTimeImmutable $now): array;
}
