<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Product;

use Sinergia\Application\Port\MercadoLivre\CredentialStore;
use Sinergia\Application\Port\Offer\CatalogSource;
use Sinergia\Application\Port\Offer\CatalogSourceFactory;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Integration\MercadoLivre\Highlight\HighlightService;
use Sinergia\Integration\MercadoLivre\Http\MercadoLivreClient;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthClient;
use Sinergia\Integration\MercadoLivre\OAuth\StoredTokenProvider;
use Sinergia\Shared\Clock\Clock;

/**
 * Monta a fonte de catálogo com o token da conta informada (StoredTokenProvider por installation_id,
 * com a renovação serializada já existente). Nunca usa INSTALLATION_SLUG nem a credencial de outra conta.
 */
final class MercadoLivreCatalogSourceFactory implements CatalogSourceFactory
{
    /** @param \Closure(): OAuthClient $oauth resolvido só quando há renovação a fazer */
    public function __construct(
        private readonly MercadoLivreClient $publicClient,
        private readonly CredentialStore $credentials,
        private readonly \Closure $oauth,
        private readonly Clock $clock,
    ) {
    }

    public function forInstallation(InstallationId $installation): CatalogSource
    {
        $client = $this->publicClient->withTokenProvider(new StoredTokenProvider(
            $installation,
            $this->credentials,
            ($this->oauth)(),
            $this->clock,
        ));

        return new MercadoLivreCatalogSource(new HighlightService($client), new ProductService($client));
    }
}
