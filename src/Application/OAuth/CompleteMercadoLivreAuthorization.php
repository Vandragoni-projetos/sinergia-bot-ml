<?php

declare(strict_types=1);

namespace Sinergia\Application\OAuth;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Port\MercadoLivre\AuthorizationCodeExchanger;
use Sinergia\Application\Port\MercadoLivre\CredentialStore;
use Sinergia\Application\Port\MercadoLivre\MercadoLivreFailure;
use Sinergia\Application\Port\MercadoLivre\OAuthStateRejected;
use Sinergia\Application\Port\MercadoLivre\OAuthStateStore;
use Sinergia\Application\Port\MercadoLivre\PendingOAuthState;
use Sinergia\Application\Port\MercadoLivre\TokenSet;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Config\SensitiveValue;

/**
 * Conclui o OAuth do Mercado Livre (callback web e `ml:oauth:finish`):
 * valida e consome o state (uso único, expira), troca o code usando o code_verifier PKCE
 * e grava os tokens cifrados. Nada sensível vai para mensagens ou logs.
 */
final class CompleteMercadoLivreAuthorization
{
    public function __construct(
        private readonly OAuthStateStore $states,
        private readonly AuthorizationCodeExchanger $exchanger,
        private readonly CredentialStore $credentials,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws OAuthAuthorizationFailed */
    public function complete(InstallationId $installation, OAuthCallbackParameters $params): TokenSet
    {
        try {
            $tokens = $this->doComplete($installation, $params);
        } catch (OAuthAuthorizationFailed $e) {
            $this->logger->warning('oauth.authorization_failed', [
                'installation_id' => $installation->value,
                'reason' => $e->reason,
                'ml_error' => $e->providerError,
            ]);
            throw $e;
        }

        $this->logger->info('oauth.authorization_completed', [
            'installation_id' => $installation->value,
            'ml_user_id' => $tokens->userId,
            'expires_in' => $tokens->expiresIn,
        ]);

        return $tokens;
    }

    /**
     * Callback web: a conta vem do PRÓPRIO state (nunca de configuração). Um state iniciado no
     * painel só é concluído se a sessão do navegador for da MESMA conta que o iniciou.
     *
     * @return InstallationId conta que recebeu a conexão
     *
     * @throws OAuthAuthorizationFailed
     */
    public function completeFromCallback(OAuthCallbackParameters $params, ?InstallationId $sessionInstallation): InstallationId
    {
        $installation = null;
        try {
            $this->assertParameters($params);
            try {
                $pending = $this->states->consumeByState($params->state ?? throw new \LogicException(), $this->clock->now());
            } catch (OAuthStateRejected $e) {
                throw self::stateFailure($e);
            }
            $installation = $pending->installationId;

            if ($pending->origin === PendingOAuthState::ORIGIN_PANEL && $sessionInstallation === null) {
                throw new OAuthAuthorizationFailed(
                    OAuthAuthorizationFailed::LOGIN_REQUIRED,
                    'Entre no painel com a conta que iniciou a conexão e tente de novo. Nada foi gravado.',
                );
            }
            // Com sessão aberta, ela precisa ser da conta dona do state (também para o fluxo do terminal).
            if ($sessionInstallation !== null && !$sessionInstallation->equals($installation)) {
                throw new OAuthAuthorizationFailed(
                    OAuthAuthorizationFailed::ACCOUNT_MISMATCH,
                    'Esta autorização foi iniciada por outra conta. Nada foi gravado.',
                );
            }

            $tokens = $this->exchangeAndSave($installation, $params->code ?? throw new \LogicException(), $pending->verifier);
        } catch (OAuthAuthorizationFailed $e) {
            $this->logger->warning('oauth.authorization_failed', [
                'installation_id' => $installation?->value,
                'session_installation_id' => $sessionInstallation?->value,
                'reason' => $e->reason,
                'ml_error' => $e->providerError,
            ]);
            throw $e;
        }

        $this->logger->info('oauth.authorization_completed', [
            'installation_id' => $installation->value,
            'ml_user_id' => $tokens->userId,
            'expires_in' => $tokens->expiresIn,
        ]);

        return $installation;
    }

    private function doComplete(InstallationId $installation, OAuthCallbackParameters $params): TokenSet
    {
        $this->assertParameters($params);

        try {
            $pending = $this->states->consume($installation, $params->state ?? throw new \LogicException(), $this->clock->now());
        } catch (OAuthStateRejected $e) {
            throw self::stateFailure($e);
        }

        return $this->exchangeAndSave($installation, $params->code ?? throw new \LogicException(), $pending['verifier']);
    }

    /** @throws OAuthAuthorizationFailed */
    private function assertParameters(OAuthCallbackParameters $params): void
    {
        if ($params->error !== null) {
            throw new OAuthAuthorizationFailed(
                OAuthAuthorizationFailed::AUTHORIZATION_DENIED,
                'O Mercado Livre não autorizou a conexão.',
                $params->error,
            );
        }
        if ($params->code === null || $params->state === null) {
            throw new OAuthAuthorizationFailed(
                OAuthAuthorizationFailed::MISSING_PARAMETERS,
                'Retorno sem "code" e "state". Nada foi gravado.',
            );
        }
    }

    private static function stateFailure(OAuthStateRejected $e): OAuthAuthorizationFailed
    {
        return new OAuthAuthorizationFailed(
            $e->reason === OAuthStateRejected::EXPIRED ? OAuthAuthorizationFailed::STATE_EXPIRED : OAuthAuthorizationFailed::STATE_UNKNOWN,
            $e->reason === OAuthStateRejected::EXPIRED
                ? 'O link de autorização expirou. Gere um novo e tente de novo.'
                : 'Link de autorização desconhecido ou já utilizado. Gere um novo e tente de novo.',
            previous: $e,
        );
    }

    /** @throws OAuthAuthorizationFailed */
    private function exchangeAndSave(InstallationId $installation, SensitiveValue $code, ?SensitiveValue $verifier): TokenSet
    {
        try {
            $tokens = $this->exchanger->exchangeCode($code, $verifier);
        } catch (MercadoLivreFailure $e) {
            throw new OAuthAuthorizationFailed(
                OAuthAuthorizationFailed::TOKEN_EXCHANGE_FAILED,
                'O Mercado Livre recusou a troca do código de autorização. Gere um novo link e tente de novo.',
                $e->failureMlError() ?? $e->failureCode(),
                $e,
            );
        }

        try {
            $this->credentials->save($installation, $this->exchanger->clientId(), $tokens, $this->clock->now());
        } catch (\RuntimeException $e) {
            throw new OAuthAuthorizationFailed(
                OAuthAuthorizationFailed::STORAGE_FAILED,
                'Os tokens foram recebidos, mas não puderam ser gravados. Nada foi salvo.',
                previous: $e,
            );
        }

        return $tokens;
    }
}
