<?php

declare(strict_types=1);

namespace Sinergia\Application\OAuth;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Port\MercadoLivre\AuthorizationUrlBuilder;
use Sinergia\Application\Port\MercadoLivre\OAuthStateStore;
use Sinergia\Application\Port\MercadoLivre\PendingOAuthState;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Config\SensitiveValue;

/**
 * Inicia a conexão Mercado Livre da conta AUTENTICADA: o state (256 bits, uso único, 10 min)
 * fica gravado — só o hash — vinculado ao installation_id e ao usuário do TenantContext.
 */
final class StartMercadoLivreConnection
{
    public const string STATE_TTL = 'PT10M';

    public function __construct(
        private readonly OAuthStateStore $states,
        private readonly AuthorizationUrlBuilder $urls,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return string URL oficial de autorização para redirecionar o navegador */
    public function start(TenantContext $tenant): string
    {
        $state = new SensitiveValue(self::base64Url(random_bytes(32)));
        $verifier = $this->urls->pkceEnabled() ? new SensitiveValue(self::base64Url(random_bytes(64))) : null;

        $this->states->create(
            $tenant->installationId,
            $state,
            $verifier,
            $this->clock->now()->add(new \DateInterval(self::STATE_TTL)),
            PendingOAuthState::ORIGIN_PANEL,
            $tenant->userId,
        );
        $this->logger->info('oauth.panel_authorization_started', [
            'installation_id' => $tenant->installationId->value,
            'user_id' => $tenant->userId,
        ]);

        return $this->urls->authorizationUrl($state, $verifier);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
