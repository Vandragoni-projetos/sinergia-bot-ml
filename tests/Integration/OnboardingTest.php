<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use DI\Container;
use Monolog\Handler\TestHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Application\Niche\NicheFilters;
use Sinergia\Application\Port\MercadoLivre\TokenSet;
use Sinergia\Application\Port\WhatsApp\ConnectionSnapshot;
use Sinergia\Application\Port\WhatsApp\ProviderInstance;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\AccountNicheRepository;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Infrastructure\Persistence\WhatsAppConnectionRepository;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Tests\Support\FakeUazapiServer;
use Sinergia\Tests\Support\PanelRequests;
use Sinergia\Tests\Support\RoutedMercadoLivreHttp;
use Sinergia\Web\HttpApp;

/**
 * Etapa 9: Primeiros passos e ativação. O estado vem SÓ dos dados da conta; o navegador não declara passo concluído.
 * Sem OAuth, Uazapi ou envio reais: HTTP externo falso.
 */
final class OnboardingTest extends DatabaseTestCase
{
    use PanelRequests;

    private const string PASSWORD = 'senha-do-painel-123';
    private const array STEPS = ['mercado_livre', 'nichos', 'whatsapp', 'destinos', 'links'];

    private \PDO $db;
    private Installation $a;
    private Installation $b;
    private int $ana;
    private int $bia;
    private Container $container;
    private RoutedMercadoLivreHttp $ml;
    private FakeUazapiServer $uazapi;
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->freshSchema();
        $installations = new InstallationRepository($this->db);
        $this->a = $installations->ensure('conta-a', 'Loja A', 'MLB');
        $this->b = $installations->ensure('conta-b', 'Loja B', 'MLB');
        $users = new UserRepository($this->db);
        $hash = (new PasswordHasher())->hash(new SensitiveValue(self::PASSWORD));
        $this->ana = $users->create($this->a->id, 'ana@loja-a.test', 'Ana', $hash);
        $this->bia = $users->create($this->b->id, 'bia@loja-b.test', 'Bia', $hash);
        $this->db->exec("INSERT INTO ml_products (ml_product_id, site_id, status, name, domain_id, permalink, picture_url, pictures_count, fetched_at)
            VALUES ('MLB400001', 'MLB', 'ok', 'Air Fryer', 'MLB-AIR_FRYERS', 'https://www.mercadolivre.com.br/p/MLB400001', 'https://http2.mlstatic.com/a.jpg', 1, NOW(3))");

        $env = array_filter(array_merge($_ENV, getenv()), 'is_string');
        $container = Kernel::container(Config::fromArray([
            'APP_ENV' => 'test', 'APP_KEY' => SecretBox::generateKeyBase64(),
            'DB_HOST' => (string) ($env['TEST_DB_HOST'] ?? ''), 'DB_PORT' => (string) ($env['TEST_DB_PORT'] ?? '3306'),
            'DB_DATABASE' => (string) ($env['TEST_DB_DATABASE'] ?? ''), 'DB_USERNAME' => (string) ($env['TEST_DB_USERNAME'] ?? ''),
            'DB_PASSWORD' => (string) ($env['TEST_DB_PASSWORD'] ?? ''),
            'ML_CLIENT_ID' => '1234567890123456', 'ML_CLIENT_SECRET' => 'segredo-ficticio', 'ML_REDIRECT_URI' => 'https://app.example.test/oauth/mercadolivre/callback',
            'UAZAPI_BASE_URL' => FakeUazapiServer::BASE_URL, 'UAZAPI_ADMIN_TOKEN' => FakeUazapiServer::ADMIN_TOKEN,
        ]), dirname(__DIR__, 2));
        self::assertInstanceOf(Container::class, $container);
        $this->container = $container;
        $this->ml = new RoutedMercadoLivreHttp();
        $this->uazapi = new FakeUazapiServer();
        $container->set('ml.http', $this->ml->client());
        $container->set('whatsapp.http', $this->uazapi->client());
        $container->set(LoggerInterface::class, LoggerFactory::create('local', new TestHandler()));
        $this->app = HttpApp::create($container);
    }

    protected function panelApp(): App
    {
        return $this->app;
    }

    public function testBrandNewAccountIsGuidedAndCannotActivate(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        self::assertSame('/comecar', $this->httpGet('/', ['sbm_session' => $ana])->getHeaderLine('Location'));

        $page = $this->page($ana);
        self::assertStringContainsString('0 de 5 passos concluídos', $page);
        self::assertStringContainsString('<button type="button" disabled>Ativar Sinergia Bot</button>', $page);
        self::assertStringContainsString('Próximo passo: <strong>Conectar o Mercado Livre</strong>', $page);
        foreach (['/conexoes', '/nichos', '/destinos', '/fila'] as $path) {
            $body = (string) $this->httpGet($path, ['sbm_session' => $ana])->getBody();
            self::assertStringContainsString('Configuração: 0 de 5 passos. Próximo: <strong>Conectar o Mercado Livre</strong>', $body, $path);
        }
        $this->assertCustomerLanguage($page);

        self::assertSame('/comecar?pendente=mercado_livre', $this->post($ana, '/comecar/ativar')->getHeaderLine('Location'));
        self::assertSame('/comecar?pendente=mercado_livre', $this->post($ana, '/fila/bot/ativar')->getHeaderLine('Location'));
        self::assertSame('paused', $this->botStatus($this->a));
    }

    /** @return iterable<string, array{string}> */
    public static function eachMissingStep(): iterable
    {
        foreach (self::STEPS as $step) {
            yield $step => [$step];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('eachMissingStep')]
    public function testEachIncompleteStepBlocksActivationOnTheServer(string $missing): void
    {
        $this->complete($this->a, $this->ana, except: [$missing]);
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);

        $page = $this->page($ana);
        self::assertStringContainsString('4 de 5 passos concluídos', $page);
        // Campos "de etapa concluída" enviados pelo navegador são ignorados.
        $forged = ['etapa' => $missing, 'concluido' => '1', 'steps[' . $missing . ']' => 'done', 'bot_status' => 'active', 'installation_id' => (string) $this->a->id->value];
        self::assertSame('/comecar?pendente=' . $missing, $this->post($ana, '/comecar/ativar', $forged)->getHeaderLine('Location'));
        self::assertSame('/comecar?pendente=' . $missing, $this->post($ana, '/fila/bot/ativar', $forged)->getHeaderLine('Location'));
        self::assertSame('paused', $this->botStatus($this->a));
        self::assertStringContainsString('<p class="alert" role="alert">', (string) $this->httpGet('/comecar', ['sbm_session' => $ana], ['pendente' => $missing])->getBody());
    }

    public function testPartialConditionsInsideAStepAreStillPending(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->complete($this->a, $this->ana, except: ['mercado_livre']);
        // Credencial do Mercado Livre existe, mas não está conectada (revogada): passo pendente.
        $this->connectMercadoLivre($this->a);
        $this->db->exec("UPDATE ml_credentials SET status = 'revoked' WHERE installation_id = " . $this->a->id->value);
        self::assertSame('/comecar?pendente=mercado_livre', $this->post($ana, '/comecar/ativar')->getHeaderLine('Location'));
        self::assertStringContainsString('Mercado Livre não conectado', $this->page($ana));
        $this->db->exec("UPDATE ml_credentials SET status = 'connected' WHERE installation_id = " . $this->a->id->value);

        // Destino cadastrado, porém pausado: não conta como pronto.
        $this->db->exec("UPDATE destinations SET status = 'paused', user_paused = 1 WHERE installation_id = " . $this->a->id->value);
        self::assertSame('/comecar?pendente=destinos', $this->post($ana, '/comecar/ativar')->getHeaderLine('Location'));
        self::assertStringContainsString('Nenhum destino pronto ainda', $this->page($ana));
        $this->db->exec("UPDATE destinations SET status = 'active', user_paused = 0 WHERE installation_id = " . $this->a->id->value);

        // Ofertas encontradas, mas o link é de OUTRA conta: condição de links não atendida.
        $this->db->exec("DELETE FROM affiliate_links WHERE installation_id = " . $this->a->id->value);
        $this->link($this->b, $this->bia, 'MLB400001', 'https://meli.la/DaContaB');
        self::assertSame('/comecar?pendente=links', $this->post($ana, '/comecar/ativar')->getHeaderLine('Location'));
        self::assertStringContainsString('1 oferta(s) aguardando link', $this->page($ana));
        self::assertSame('paused', $this->botStatus($this->a));
    }

    public function testMercadoLivreStepDependsOnlyOnTheConnectionAndMediaDeclarationDoesNotInterfere(): void
    {
        $this->complete($this->a, $this->ana);
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        self::assertSame([['0']], $this->rows('SELECT COUNT(*) FROM affiliate_media_declarations'));

        // Conectado e SEM declaração de Mídias: passo concluído.
        $page = $this->page($ana);
        self::assertStringContainsString('5 de 5 passos concluídos', $page);
        self::assertStringContainsString('Mercado Livre conectado', $page);
        self::assertStringNotContainsString('declaração de Mídias', $page);

        // Desconectado: pendente de novo; reconectado: concluído.
        $this->db->exec("UPDATE ml_credentials SET status = 'expired' WHERE installation_id = " . $this->a->id->value);
        self::assertStringContainsString('4 de 5 passos concluídos', $this->page($ana));
        self::assertSame('/comecar?pendente=mercado_livre', $this->post($ana, '/fila/bot/ativar')->getHeaderLine('Location'));
        $this->db->exec("UPDATE ml_credentials SET status = 'connected' WHERE installation_id = " . $this->a->id->value);

        // Registrar e remover a declaração pelo painel continua funcionando e não muda o checklist.
        self::assertSame('/conexoes?afiliado=declarado', $this->post($ana, '/conexoes/afiliado/declaracao', ['acao' => 'declarar', 'confirmo' => '1'])->getHeaderLine('Location'));
        self::assertSame([['1', '0']], $this->rows('SELECT COUNT(*), SUM(revoked_at IS NOT NULL) FROM affiliate_media_declarations WHERE installation_id = ?', [$this->a->id->value]));
        self::assertStringContainsString('5 de 5 passos concluídos', $this->page($ana));
        self::assertSame('/conexoes?afiliado=removido', $this->post($ana, '/conexoes/afiliado/declaracao', ['acao' => 'remover'])->getHeaderLine('Location'));
        self::assertSame([['1', '1']], $this->rows('SELECT COUNT(*), SUM(revoked_at IS NOT NULL) FROM affiliate_media_declarations WHERE installation_id = ?', [$this->a->id->value]));
        self::assertStringContainsString('5 de 5 passos concluídos', $this->page($ana));
        self::assertSame('paused', $this->botStatus($this->a), 'Nada disso ativa o bot.');

        // Sem declaração vigente, a ativação (só por ação explícita) é aceita.
        self::assertSame('/fila?ok=bot_ativado', $this->post($ana, '/comecar/ativar')->getHeaderLine('Location'));
        self::assertSame('active', $this->botStatus($this->a));
        self::assertSame('paused', $this->botStatus($this->b), 'Outra conta não é afetada.');
    }

    public function testCompleteOnboardingNeverActivatesAloneAndValidActivationGoesToQueue(): void
    {
        $this->complete($this->a, $this->ana);
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);

        $page = $this->page($ana);
        self::assertStringContainsString('5 de 5 passos concluídos', $page);
        self::assertStringContainsString('<button type="submit" class="primary-inline">Ativar Sinergia Bot</button>', $page);
        self::assertSame('paused', $this->botStatus($this->a), 'Concluir os passos não ativa o bot sozinho.');
        self::assertStringContainsString('Tudo pronto. <a href="/comecar">Ativar Sinergia Bot</a>', (string) $this->httpGet('/fila', ['sbm_session' => $ana])->getBody());

        self::assertSame('/fila?ok=bot_ativado', $this->post($ana, '/comecar/ativar')->getHeaderLine('Location'));
        self::assertSame('active', $this->botStatus($this->a));
        self::assertSame([[(string) $this->ana]], $this->rows('SELECT onboarding_completed_by FROM installations WHERE id = ?', [$this->a->id->value]));
        $queue = (string) $this->httpGet('/fila', ['sbm_session' => $ana], ['ok' => 'bot_ativado'])->getBody();
        self::assertStringContainsString('● Bot trabalhando', $queue);
        self::assertStringContainsString('Bot ativado.', $queue);
        self::assertSame('/fila', $this->httpGet('/', ['sbm_session' => $ana])->getHeaderLine('Location'));
        self::assertStringNotContainsString('Configuração:', $queue);

        // Pausar continua livre; reativar pela Fila volta a checar tudo (ex.: WhatsApp caiu).
        $this->post($ana, '/fila/bot/pausar');
        $this->db->exec("UPDATE whatsapp_connections SET status = 'disconnected' WHERE installation_id = " . $this->a->id->value);
        self::assertSame('/comecar?pendente=whatsapp', $this->post($ana, '/fila/bot/ativar')->getHeaderLine('Location'));
        self::assertSame('paused', $this->botStatus($this->a));
    }

    public function testCompleteAccountAndIncompleteAccountAreIndependent(): void
    {
        $this->complete($this->a, $this->ana);
        $this->complete($this->b, $this->bia, except: ['whatsapp', 'links']);
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $bia = $this->signIn('bia@loja-b.test', self::PASSWORD);

        self::assertStringContainsString('3 de 5 passos concluídos', $this->page($bia));
        self::assertSame('/comecar?pendente=whatsapp', $this->post($bia, '/comecar/ativar')->getHeaderLine('Location'));
        self::assertSame('/fila?ok=bot_ativado', $this->post($ana, '/comecar/ativar')->getHeaderLine('Location'));
        self::assertSame(['active', 'paused'], [$this->botStatus($this->a), $this->botStatus($this->b)]);
        // A ativação de A não muda nada em B; B continua vendo só o próprio estado.
        self::assertStringContainsString('3 de 5 passos concluídos', $this->page($bia));
        self::assertSame('/comecar', $this->httpGet('/', ['sbm_session' => $bia])->getHeaderLine('Location'));
        self::assertSame([[null]], $this->rows('SELECT onboarding_completed_at FROM installations WHERE id = ?', [$this->b->id->value]));
    }

    public function testOfferSearchFromThePanel(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        self::assertSame('/comecar?pendente=mercado_livre', $this->post($ana, '/comecar/ofertas')->getHeaderLine('Location'));
        $this->connectMercadoLivre($this->a);
        self::assertSame('/comecar?pendente=nichos', $this->post($ana, '/comecar/ofertas')->getHeaderLine('Location'));
        $this->chooseNiche($this->a, $this->ana);

        $this->ml->ranking('MLB456045', [['MLB400001']])
            ->product('MLB400001', 'MLB-AIR_FRYERS')
            ->offers('MLB400001', [['price' => 299.9, 'original_price' => 399.9]]);
        self::assertSame('/comecar?ofertas=1#ofertas', $this->post($ana, '/comecar/ofertas')->getHeaderLine('Location'));
        self::assertSame(['TESTE-ml-conta-a'], array_values(array_unique(array_column($this->ml->requests, 'token'))), 'Busca com o token da própria conta.');
        self::assertStringContainsString('1 oferta(s) aguardando link', $this->page($ana));
        self::assertSame('/comecar?erro=busca_cedo#ofertas', $this->post($ana, '/comecar/ofertas')->getHeaderLine('Location'));
    }

    public function testCsrfAndSessionAreRequired(): void
    {
        $this->complete($this->a, $this->ana);
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $csrfB = $this->csrfFor($this->signIn('bia@loja-b.test', self::PASSWORD), '/comecar');
        foreach (['/comecar/ativar', '/comecar/ofertas', '/fila/bot/ativar'] as $path) {
            self::assertSame(400, $this->httpPost($path, [], ['sbm_session' => $ana])->getStatusCode(), $path);
            self::assertSame(400, $this->httpPost($path, ['_csrf' => $csrfB], ['sbm_session' => $ana])->getStatusCode(), $path);
            self::assertSame('/entrar', $this->httpPost($path, ['_csrf' => 'x'])->getHeaderLine('Location'), $path);
        }
        self::assertSame('/entrar', $this->httpGet('/comecar')->getHeaderLine('Location'));
        self::assertSame('/entrar', $this->httpGet('/')->getHeaderLine('Location'));
        self::assertSame('paused', $this->botStatus($this->a));
    }

    /** @param list<string> $except */
    private function complete(Installation $inst, int $user, array $except = []): void
    {
        // Passo Mercado Livre = só a conexão; a declaração de Mídias da conta NÃO é registrada aqui (não é exigida).
        if (!in_array('mercado_livre', $except, true)) {
            $this->connectMercadoLivre($inst);
        }
        // Subnicho é pré-requisito de destino pronto (FK); "sem nichos" é simulado desativando o nicho no catálogo no fim.
        $this->chooseNiche($inst, $user);
        if (!in_array('whatsapp', $except, true)) {
            $repo = $this->container->get(WhatsAppConnectionRepository::class);
            $repo->ensure($inst->id, 'uazapi', 'sbm-' . $inst->slug, $user, new \DateTimeImmutable());
            $repo->storeInstance($inst->id, new ProviderInstance(null, 'sbm-' . $inst->slug, new SensitiveValue('TESTE-wa-' . $inst->slug)), $user, new \DateTimeImmutable());
            $repo->recordSnapshot($inst->id, new ConnectionSnapshot(ConnectionSnapshot::CONNECTED), new \DateTimeImmutable());
        }
        if (!in_array('destinos', $except, true)) {
            $this->readyDestination($inst, $user);
        }
        if (!in_array('links', $except, true)) {
            $this->db->prepare("INSERT INTO offer_selection_runs (installation_id, status, started_at, finished_at) VALUES (?, 'completed', NOW(3) - INTERVAL 1 HOUR, NOW(3))")->execute([$inst->id->value]);
            $run = (int) $this->db->lastInsertId();
            $this->db->prepare("INSERT INTO account_offer_candidates (installation_id, run_id, ml_product_id, niche_id, subniche_id, ml_category_id, ranking_position, sort_order, item_id, price, original_price, discount_pct, selection_rule)
                VALUES (?, ?, 'MLB400001', ?, ?, 'MLB456045', 1, 1, 'MLB7000000001', 199.90, 249.90, 20, 'buy_box')")
                ->execute([$inst->id->value, $run, $this->nicheId(), $this->subId()]);
            $this->link($inst, $user, 'MLB400001', 'https://meli.la/Conta' . strtoupper($inst->slug[6]));
        }
        if (in_array('nichos', $except, true)) {
            $this->db->exec("UPDATE niches SET active = 0 WHERE slug = 'casa-cozinha'");
        }
    }

    private function connectMercadoLivre(Installation $inst): void
    {
        $this->container->get(MlCredentialRepository::class)->save($inst->id, '1234567890123456', new TokenSet(
            new SensitiveValue('TESTE-ml-' . $inst->slug), new SensitiveValue('TESTE-ml-r-' . $inst->slug), 21600, 'offline_access', 1, 'Bearer',
        ), new \DateTimeImmutable());
    }

    private function chooseNiche(Installation $inst, int $user): void
    {
        (new AccountNicheRepository($this->db))->save($inst->id, $this->nicheId(), [$this->subId()], NicheFilters::defaults(), $user, new \DateTimeImmutable());
    }

    private function readyDestination(Installation $inst, int $user): void
    {
        $this->db->prepare("INSERT INTO destinations (installation_id, public_key, type, provider_ref, name, niche_id, mode, interval_minutes, user_paused, status, eligibility,
                tech_present, tech_is_channel, tech_we_are_admin, tech_has_invite_link, tech_join_approval, public_declared, media_registered_declared, created_at, created_by_user_id, updated_at)
            VALUES (?, ?, 'group', ?, 'Grupo', ?, 'auto', 60, 0, 'active', 'group_declared_public', 1, 0, 1, 1, 0, 1, 1, NOW(3), ?, NOW(3))")
            ->execute([$inst->id->value, bin2hex(random_bytes(10)), '120363' . $inst->id->value . '00001@g.us', $this->nicheId(), $user]);
        $id = (int) $this->db->lastInsertId();
        $this->db->prepare('INSERT INTO destination_subniches (installation_id, destination_id, niche_id, subniche_id) VALUES (?, ?, ?, ?)')
            ->execute([$inst->id->value, $id, $this->nicheId(), $this->subId()]);
    }

    private function link(Installation $inst, int $user, string $product, string $url): void
    {
        $this->db->prepare("INSERT INTO affiliate_links (installation_id, site_id, ml_product_id, original_url, affiliate_url, affiliate_url_sha256, source, status, received_at, confirmed_at, confirmed_by_user_id, created_at, updated_at)
            VALUES (?, 'MLB', ?, 'https://www.mercadolivre.com.br/p/x', ?, SHA2(?, 256), 'manual_batch', 'active', NOW(3), NOW(3), ?, NOW(3), NOW(3))")
            ->execute([$inst->id->value, $product, $url, $url, $user]);
    }

    private function nicheId(): int
    {
        return (int) $this->db->query("SELECT id FROM niches WHERE slug = 'casa-cozinha'")->fetchColumn();
    }

    private function subId(): int
    {
        return (int) $this->db->query("SELECT id FROM subniches WHERE slug = 'air-fryers'")->fetchColumn();
    }

    private function botStatus(Installation $inst): string
    {
        return (string) $this->rows('SELECT bot_status FROM installations WHERE id = ?', [$inst->id->value])[0][0];
    }

    /** @param array<string, mixed> $body */
    private function post(string $session, string $path, array $body = []): ResponseInterface
    {
        return $this->httpPost($path, ['_csrf' => $this->csrfFor($session, '/comecar')] + $body, ['sbm_session' => $session]);
    }

    private function page(string $session): string
    {
        return (string) $this->httpGet('/comecar', ['sbm_session' => $session])->getBody();
    }

    private function assertCustomerLanguage(string $html): void
    {
        foreach (['installation', 'provider_ref', 'worker', 'dispatch', 'Uazapi', 'JID', 'MLB'] as $term) {
            self::assertStringNotContainsString($term, $html, 'Termo técnico visível: ' . $term);
        }
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<list<?string>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $r): array => array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $r), $stmt->fetchAll(\PDO::FETCH_NUM));
    }
}
