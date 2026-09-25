<?php

declare(strict_types=1);

namespace Sinergia;

use DI\ContainerBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinergia\Application\Auth\AuthService;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Application\Affiliate\BatchMatcher;
use Sinergia\Application\Affiliate\ManualBatchAffiliateLinkProvider;
use Sinergia\Application\Affiliate\MeliLaShortLinkFormat;
use Sinergia\Application\Destination\ManageDestinations;
use Sinergia\Application\Port\Affiliate\AffiliateLinkProvider;
use Sinergia\Application\Niche\SaveNicheSelection;
use Sinergia\Application\Offer\OfferChooser;
use Sinergia\Application\Offer\SelectOffers;
use Sinergia\Application\WhatsApp\ManageWhatsAppConnection;
use Sinergia\Application\OAuth\CompleteMercadoLivreAuthorization;
use Sinergia\Application\OAuth\StartMercadoLivreConnection;
use Sinergia\Application\Validation\EvidenceWriter;
use Sinergia\Application\Validation\ValidateCategory;
use Sinergia\Application\Validation\ValidateHighlights;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Database\ConnectionFactory;
use Sinergia\Infrastructure\Database\Migrator;
use Sinergia\Infrastructure\Persistence\AccountNicheRepository;
use Sinergia\Infrastructure\Persistence\AffiliateLinkRepository;
use Sinergia\Infrastructure\Persistence\DestinationRepository;
use Sinergia\Infrastructure\Persistence\DiscoveryRunRepository;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\LoginAttemptRepository;
use Sinergia\Infrastructure\Persistence\MediaDeclarationRepository;
use Sinergia\Infrastructure\Persistence\MlConnectionStatusRepository;
use Sinergia\Infrastructure\Persistence\NicheCatalogRepository;
use Sinergia\Infrastructure\Persistence\OfferSelectionRepository;
use Sinergia\Infrastructure\Persistence\PublicCatalogCacheRepository;
use Sinergia\Infrastructure\Persistence\SessionRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Infrastructure\Persistence\WhatsAppConnectionRepository;
use Sinergia\Infrastructure\Persistence\MlCategoryRepository;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Infrastructure\Persistence\OAuthStateRepository;
use Sinergia\Integration\MercadoLivre\Category\CategoryService;
use Sinergia\Integration\MercadoLivre\Highlight\HighlightService;
use Sinergia\Integration\MercadoLivre\Http\MercadoLivreClient;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthClient;
use Sinergia\Integration\MercadoLivre\OAuth\StoredTokenProvider;
use Sinergia\Integration\MercadoLivre\Product\MercadoLivreCatalogSourceFactory;
use Sinergia\Integration\WhatsApp\Uazapi\UazapiProvider;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Clock\SystemClock;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Web\Security\Csrf;
use Sinergia\Web\Security\PanelCookies;
use Sinergia\Web\View\Views;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;

use function DI\factory;

/**
 * Monta o container. Tudo é preguiçoso: o /health não abre banco nem exige credenciais;
 * cada serviço só exige a configuração de que realmente precisa, no momento do uso.
 */
final class Kernel
{
    public static function container(Config $config, string $projectRoot): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(false);
        $builder->addDefinitions([
            Config::class => $config,
            'project_root' => $projectRoot,
            Clock::class => factory(static fn () => new SystemClock()),
            LoggerInterface::class => factory(static fn (Config $c) => LoggerFactory::create($c->appEnv())),

            \PDO::class => factory(static fn (Config $c) => ConnectionFactory::create($c->database())),
            SecretBox::class => factory(static fn (Config $c) => new SecretBox($c->appKey())),

            Migrator::class => factory(static fn (ContainerInterface $c) => new Migrator(
                $c->get(\PDO::class),
                $c->get('project_root') . '/database/migrations',
            )),
            InstallationRepository::class => factory(static fn (ContainerInterface $c) => new InstallationRepository($c->get(\PDO::class))),
            MlCategoryRepository::class => factory(static fn (ContainerInterface $c) => new MlCategoryRepository($c->get(\PDO::class))),
            DiscoveryRunRepository::class => factory(static fn (ContainerInterface $c) => new DiscoveryRunRepository($c->get(\PDO::class))),
            MlCredentialRepository::class => factory(static fn (ContainerInterface $c) => new MlCredentialRepository(
                $c->get(\PDO::class),
                $c->get(SecretBox::class),
            )),
            OAuthStateRepository::class => factory(static fn (ContainerInterface $c) => new OAuthStateRepository(
                $c->get(\PDO::class),
                $c->get(SecretBox::class),
            )),

            // Painel: acesso, sessão e visualização (F1, etapa 1).
            UserRepository::class => factory(static fn (ContainerInterface $c) => new UserRepository($c->get(\PDO::class))),
            SessionRepository::class => factory(static fn (ContainerInterface $c) => new SessionRepository($c->get(\PDO::class))),
            LoginAttemptRepository::class => factory(static fn (ContainerInterface $c) => new LoginAttemptRepository($c->get(\PDO::class))),
            PasswordHasher::class => factory(static fn () => new PasswordHasher()),
            AuthService::class => factory(static fn (ContainerInterface $c) => new AuthService(
                $c->get(UserRepository::class),
                $c->get(SessionRepository::class),
                $c->get(LoginAttemptRepository::class),
                $c->get(PasswordHasher::class),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
            )),
            // Conexões / Mercado Livre pelo painel (F1, etapa 2).
            StartMercadoLivreConnection::class => factory(static fn (ContainerInterface $c) => new StartMercadoLivreConnection(
                $c->get(OAuthStateRepository::class),
                $c->get(OAuthClient::class),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
            )),
            MlConnectionStatusRepository::class => factory(static fn (ContainerInterface $c) => new MlConnectionStatusRepository($c->get(\PDO::class))),
            MediaDeclarationRepository::class => factory(static fn (ContainerInterface $c) => new MediaDeclarationRepository($c->get(\PDO::class))),
            // Nichos: catálogo global (só leitura) + preferências privadas por conta (F1, etapa 3).
            NicheCatalogRepository::class => factory(static fn (ContainerInterface $c) => new NicheCatalogRepository($c->get(\PDO::class))),
            AccountNicheRepository::class => factory(static fn (ContainerInterface $c) => new AccountNicheRepository($c->get(\PDO::class))),
            SaveNicheSelection::class => factory(static fn (ContainerInterface $c) => new SaveNicheSelection(
                $c->get(NicheCatalogRepository::class),
                $c->get(AccountNicheRepository::class),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
                $c->get(Config::class)->siteId(),
            )),
            Csrf::class => factory(static fn (Config $c) => new Csrf($c->appKey())),
            PanelCookies::class => factory(static fn (Config $c) => new PanelCookies(!in_array($c->appEnv(), ['local', 'test'], true))),
            Views::class => factory(static fn (ContainerInterface $c) => new Views(new TwigEnvironment(
                new TwigFilesystemLoader($c->get('project_root') . '/templates'),
                [
                    'autoescape' => 'html',
                    'strict_variables' => true,
                    'cache' => $c->get(Config::class)->isProduction() ? $c->get('project_root') . '/storage/cache/twig' : false,
                ],
            ))),

            Installation::class => factory(static function (ContainerInterface $c): Installation {
                $slug = $c->get(Config::class)->installationSlug();
                $installation = $c->get(InstallationRepository::class)->findBySlug($slug);
                if ($installation === null) {
                    throw new \RuntimeException(sprintf(
                        'Instalação "%s" não existe. Rode: php bin/console installation:ensure-default',
                        $slug,
                    ));
                }

                return $installation;
            }),

            'ml.http' => factory(static function (Config $c) {
                $ml = $c->mercadoLivre();

                return new GuzzleClient([
                    'timeout' => $ml->timeoutSeconds,
                    'connect_timeout' => min(10, $ml->timeoutSeconds),
                    'http_errors' => false,
                    'allow_redirects' => false,
                ]);
            }),
            HttpFactory::class => factory(static fn () => new HttpFactory()),

            // Cliente sem token (sempre disponível).
            'ml.client.public' => factory(static fn (ContainerInterface $c) => new MercadoLivreClient(
                $c->get('ml.http'),
                $c->get(HttpFactory::class),
                $c->get(HttpFactory::class),
                $c->get(Config::class)->mercadoLivre(),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
            )),
            OAuthClient::class => factory(static fn (ContainerInterface $c) => new OAuthClient(
                $c->get('ml.client.public'),
                $c->get(Config::class)->mercadoLivre(),
                $c->get(Config::class)->mercadoLivreOAuth(),
            )),
            // Seletor de ofertas (F1, etapa 4): cache público + dados privados por conta; token da PRÓPRIA conta.
            PublicCatalogCacheRepository::class => factory(static fn (ContainerInterface $c) => new PublicCatalogCacheRepository($c->get(\PDO::class))),
            OfferSelectionRepository::class => factory(static fn (ContainerInterface $c) => new OfferSelectionRepository($c->get(\PDO::class))),
            MercadoLivreCatalogSourceFactory::class => factory(static fn (ContainerInterface $c) => new MercadoLivreCatalogSourceFactory(
                $c->get('ml.client.public'),
                $c->get(MlCredentialRepository::class),
                static fn (): OAuthClient => $c->get(OAuthClient::class),
                $c->get(Clock::class),
            )),
            SelectOffers::class => factory(static fn (ContainerInterface $c) => new SelectOffers(
                $c->get(OfferSelectionRepository::class),
                $c->get(PublicCatalogCacheRepository::class),
                $c->get(MercadoLivreCatalogSourceFactory::class),
                new OfferChooser(),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
                $c->get(Config::class)->siteId(),
            )),
            // WhatsApp por conta (F1, etapa 5): provedor Uazapi atrás do contrato WhatsAppProvider.
            'whatsapp.http' => factory(static function (Config $c) {
                $timeout = $c->hasUazapi() ? $c->uazapi()->timeoutSeconds : 15;

                return new GuzzleClient([
                    'timeout' => $timeout,
                    'connect_timeout' => min(5, $timeout),
                    'http_errors' => false,
                    'allow_redirects' => false,
                ]);
            }),
            UazapiProvider::class => factory(static fn (ContainerInterface $c) => new UazapiProvider(
                $c->get('whatsapp.http'),
                $c->get(HttpFactory::class),
                $c->get(HttpFactory::class),
                $c->get(Config::class)->uazapi(),
                $c->get(LoggerInterface::class),
            )),
            WhatsAppConnectionRepository::class => factory(static fn (ContainerInterface $c) => new WhatsAppConnectionRepository(
                $c->get(\PDO::class),
                $c->get(SecretBox::class),
            )),
            ManageWhatsAppConnection::class => factory(static fn (ContainerInterface $c) => new ManageWhatsAppConnection(
                $c->get(WhatsAppConnectionRepository::class),
                static fn (): UazapiProvider => $c->get(UazapiProvider::class),
                $c->get(Config::class)->hasUazapi(),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
            )),
            // Destinos (F1, etapa 6): só da própria conexão WhatsApp da conta.
            DestinationRepository::class => factory(static fn (ContainerInterface $c) => new DestinationRepository($c->get(\PDO::class))),
            ManageDestinations::class => factory(static fn (ContainerInterface $c) => new ManageDestinations(
                $c->get(DestinationRepository::class),
                $c->get(WhatsAppConnectionRepository::class),
                static fn (): UazapiProvider => $c->get(UazapiProvider::class),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
                $c->get(Config::class)->whatsAppTestImageUrl(),
            )),
            // Biblioteca de links e lotes (F1, etapa 7): manual_batch pelo Gerador oficial; nenhum link é aberto.
            AffiliateLinkRepository::class => factory(static fn (ContainerInterface $c) => new AffiliateLinkRepository($c->get(\PDO::class))),
            BatchMatcher::class => factory(static fn () => new BatchMatcher([new MeliLaShortLinkFormat()])),
            ManualBatchAffiliateLinkProvider::class => factory(static fn (ContainerInterface $c) => new ManualBatchAffiliateLinkProvider(
                $c->get(AffiliateLinkRepository::class),
                $c->get(BatchMatcher::class),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
            )),
            AffiliateLinkProvider::class => factory(static fn (ContainerInterface $c) => $c->get(ManualBatchAffiliateLinkProvider::class)),
            // Conclusão do OAuth, compartilhada pelo callback web e pelo ml:oauth:finish.
            CompleteMercadoLivreAuthorization::class => factory(static fn (ContainerInterface $c) => new CompleteMercadoLivreAuthorization(
                $c->get(OAuthStateRepository::class),
                $c->get(OAuthClient::class),
                $c->get(MlCredentialRepository::class),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
            )),
            // Token OAuth da instalação com renovação serializada (usado pelo cliente e pelo ml:oauth:refresh).
            StoredTokenProvider::class => factory(static fn (ContainerInterface $c) => new StoredTokenProvider(
                $c->get(Installation::class)->id,
                $c->get(MlCredentialRepository::class),
                $c->get(OAuthClient::class),
                $c->get(Clock::class),
            )),
            // Cliente com o token OAuth da instalação (renovação automática e serializada).
            MercadoLivreClient::class => factory(static fn (ContainerInterface $c) => $c->get('ml.client.public')->withTokenProvider(
                $c->get(StoredTokenProvider::class),
            )),

            'ml.categories.public' => factory(static fn (ContainerInterface $c) => new CategoryService($c->get('ml.client.public'))),
            'ml.highlights.public' => factory(static fn (ContainerInterface $c) => new HighlightService($c->get('ml.client.public'))),
            CategoryService::class => factory(static fn (ContainerInterface $c) => new CategoryService($c->get(MercadoLivreClient::class))),
            HighlightService::class => factory(static fn (ContainerInterface $c) => new HighlightService($c->get(MercadoLivreClient::class))),

            EvidenceWriter::class => factory(static fn (ContainerInterface $c) => new EvidenceWriter(
                $c->get('project_root') . '/storage/validation',
            )),
        ]);

        return $builder->build();
    }

    /** Casos de uso montados sob demanda, escolhendo cliente com ou sem OAuth. */
    public static function validateHighlights(ContainerInterface $c, bool $withOAuth): ValidateHighlights
    {
        return new ValidateHighlights(
            $c->get($withOAuth ? HighlightService::class : 'ml.highlights.public'),
            $c->get(DiscoveryRunRepository::class),
            $c->get(EvidenceWriter::class),
            $c->get(Clock::class),
            $c->get(LoggerInterface::class),
        );
    }

    public static function validateCategory(ContainerInterface $c, bool $withOAuth): ValidateCategory
    {
        return new ValidateCategory(
            $c->get($withOAuth ? CategoryService::class : 'ml.categories.public'),
            $c->get(MlCategoryRepository::class),
            $c->get(DiscoveryRunRepository::class),
            $c->get(EvidenceWriter::class),
            $c->get(Clock::class),
            $c->get(LoggerInterface::class),
        );
    }
}
