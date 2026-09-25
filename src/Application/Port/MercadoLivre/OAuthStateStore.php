<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Config\SensitiveValue;

/** Estados OAuth pendentes (uso único, com expiração). Só o hash do state é guardado. */
interface OAuthStateStore
{
    public function create(
        InstallationId $installation,
        SensitiveValue $state,
        ?SensitiveValue $codeVerifier,
        \DateTimeImmutable $expiresAt,
        string $origin = PendingOAuthState::ORIGIN_CLI,
        ?int $startedByUserId = null,
    ): void;

    /**
     * Consome o state de uma conta conhecida (fluxo do terminal).
     *
     * @return array{verifier: ?SensitiveValue}
     *
     * @throws OAuthStateRejected
     */
    public function consume(InstallationId $installation, SensitiveValue $state, \DateTimeImmutable $now): array;

    /**
     * Consome o state e descobre a conta dona dele (callback web). Some mesmo se expirado.
     *
     * @throws OAuthStateRejected
     */
    public function consumeByState(SensitiveValue $state, \DateTimeImmutable $now): PendingOAuthState;
}
