<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\OAuth;

use Sinergia\Application\Port\MercadoLivre\CredentialStore;
use Sinergia\Application\Port\MercadoLivre\StoredCredential;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Integration\MercadoLivre\Http\AccessTokenProvider;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Config\SensitiveValue;

/**
 * Entrega um access token válido da instalação.
 * O refresh_token é de USO ÚNICO (docs oficiais): a renovação acontece dentro de
 * CredentialStore::withLockedCredential, para nunca haver dois refresh simultâneos.
 * Não conhece banco: só o contrato CredentialStore.
 */
final class StoredTokenProvider implements AccessTokenProvider
{
    private const int REFRESH_MARGIN_SECONDS = 300;

    public function __construct(
        private readonly InstallationId $installation,
        private readonly CredentialStore $credentials,
        private readonly OAuthClient $oauth,
        private readonly Clock $clock,
    ) {
    }

    public function accessToken(): SensitiveValue
    {
        $now = $this->clock->now();
        $current = $this->credentials->find($this->installation);
        if ($current === null) {
            throw new OAuthException('Nenhuma credencial do Mercado Livre conectada para esta instalação.', 'oauth_not_connected');
        }
        if ($current->accessValidFor($now, self::REFRESH_MARGIN_SECONDS)) {
            $this->credentials->touchApiCall($this->installation, $now);

            return $current->accessToken;
        }

        try {
            return $this->credentials->withLockedCredential(
                $this->installation,
                fn (?StoredCredential $locked): SensitiveValue => $this->refreshIfStillNeeded($locked, $now),
            );
        } catch (OAuthException $e) {
            if (in_array($e->errorCode, ['invalid_grant', 'refresh_unavailable'], true)) {
                $this->credentials->markStatus($this->installation, 'expired');
            }
            throw $e;
        }
    }

    private function refreshIfStillNeeded(?StoredCredential $locked, \DateTimeImmutable $now): SensitiveValue
    {
        if ($locked === null) {
            throw new OAuthException('Credencial removida durante a renovação.', 'oauth_not_connected');
        }
        // Outro processo pode ter renovado enquanto aguardávamos o lock.
        if ($locked->accessValidFor($now, self::REFRESH_MARGIN_SECONDS)) {
            return $locked->accessToken;
        }
        if ($locked->refreshToken === null) {
            throw new OAuthException('Token expirado e sem refresh_token. Conecte a conta novamente.', 'refresh_unavailable');
        }

        $tokens = $this->oauth->refresh($locked->refreshToken);
        $this->credentials->save($this->installation, $locked->clientId, $tokens, $now);

        return $tokens->accessToken;
    }
}
