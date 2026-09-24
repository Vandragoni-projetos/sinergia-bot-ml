<?php

declare(strict_types=1);

namespace Sinergia;

use DI\ContainerBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sinergia\Application\OAuth\CompleteMercadoLivreAuthorization;
use Sinergia\Application\Validation\EvidenceWriter;
use Sinergia\Application\Validation\ValidateCategory;
use Sinergia\Application\Validation\ValidateHighlights;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Database\ConnectionFactory;
use Sinergia\Infrastructure\Database\Migrator;
use Sinergia\Infrastructure\Persistence\DiscoveryRunRepository;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\MlCategoryRepository;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Infrastructure\Persistence\OAuthStateRepository;
use Sinergia\Integration\MercadoLivre\Category\CategoryService;
use Sinergia\Integration\MercadoLivre\Highlight\HighlightService;
use Sinergia\Integration\MercadoLivre\Http\MercadoLivreClient;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthClient;
use Sinergia\Integration\MercadoLivre\OAuth\StoredTokenProvider;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Clock\SystemClock;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Logging\LoggerFactory;

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
