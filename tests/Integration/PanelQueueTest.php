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
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\AccountNicheRepository;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Tests\Support\PanelRequests;
use Sinergia\Web\HttpApp;

/** Etapa 8: tela Fila (Próximas, Aguardando aprovação, Aguardando link, Enviadas) e ações por conta. */
final class PanelQueueTest extends DatabaseTestCase
{
    use PanelRequests;

    private const string PASSWORD = 'senha-do-painel-123';

    private \PDO $db;
    private Installation $a;
    private Installation $b;
    private int $ana;
    private int $bia;
    private App $app;
    /** @var array<string, string> */
    private array $keys = [];

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
        $this->db->exec("INSERT INTO ml_products (ml_product_id, site_id, status, name, permalink, picture_url, pictures_count, fetched_at) VALUES
            ('MLB300001', 'MLB', 'ok', 'Air Fryer Aprovar', 'https://www.mercadolivre.com.br/p/MLB300001', 'https://http2.mlstatic.com/a.jpg', 1, NOW(3)),
            ('MLB300002', 'MLB', 'ok', 'Panela Agendada', 'https://www.mercadolivre.com.br/p/MLB300002', 'https://http2.mlstatic.com/b.jpg', 1, NOW(3)),
            ('MLB300003', 'MLB', 'ok', 'Cafeteira Sem Link', 'https://www.mercadolivre.com.br/p/MLB300003', 'https://http2.mlstatic.com/c.jpg', 1, NOW(3)),
            ('MLB300004', 'MLB', 'ok', 'Mixer Enviado', 'https://www.mercadolivre.com.br/p/MLB300004', 'https://http2.mlstatic.com/d.jpg', 1, NOW(3)),
            ('MLB300005', 'MLB', 'ok', 'Produto da Conta B', 'https://www.mercadolivre.com.br/p/MLB300005', 'https://http2.mlstatic.com/e.jpg', 1, NOW(3))");

        $destA = $this->destination($this->a, $this->ana, 'Grupo Ofertas A');
        $destB = $this->destination($this->b, $this->bia, 'Grupo Ofertas B');
        $linkA1 = $this->link($this->a, $this->ana, 'MLB300001', 'https://meli.la/LinkA01');
        $linkA2 = $this->link($this->a, $this->ana, 'MLB300002', 'https://meli.la/LinkA02');
        $linkA4 = $this->link($this->a, $this->ana, 'MLB300004', 'https://meli.la/LinkA04');
        $linkB5 = $this->link($this->b, $this->bia, 'MLB300005', 'https://meli.la/LinkB05');
        $this->item('pendA', $this->a, $destA, 'MLB300001', 'pending_approval', $linkA1);
        $this->item('schedA', $this->a, $destA, 'MLB300002', 'scheduled', $linkA2);
        $this->item('awaitA', $this->a, $destA, 'MLB300003', 'awaiting_affiliate_link', null);
        $this->item('sentA', $this->a, $destA, 'MLB300004', 'sent', $linkA4);
        $this->item('pendB', $this->b, $destB, 'MLB300005', 'pending_approval', $linkB5);

        $env = array_filter(array_merge($_ENV, getenv()), 'is_string');
        $container = Kernel::container(Config::fromArray([
            'APP_ENV' => 'test', 'APP_KEY' => SecretBox::generateKeyBase64(),
            'DB_HOST' => (string) ($env['TEST_DB_HOST'] ?? ''), 'DB_PORT' => (string) ($env['TEST_DB_PORT'] ?? '3306'),
            'DB_DATABASE' => (string) ($env['TEST_DB_DATABASE'] ?? ''), 'DB_USERNAME' => (string) ($env['TEST_DB_USERNAME'] ?? ''),
            'DB_PASSWORD' => (string) ($env['TEST_DB_PASSWORD'] ?? ''),
        ]), dirname(__DIR__, 2));
        self::assertInstanceOf(Container::class, $container);
        $container->set(LoggerInterface::class, LoggerFactory::create('local', new TestHandler()));
        $this->app = HttpApp::create($container);
    }

    protected function panelApp(): App
    {
        return $this->app;
    }

    public function testPageShowsAllSectionsWithoutInternalIdentifiers(): void
    {
        $page = $this->page($this->signIn('ana@loja-a.test', self::PASSWORD));

        foreach (['Bot pausado', 'Ativar bot', 'Aguardando aprovação (1)', 'Air Fryer Aprovar', 'Próximas (1)', 'Panela Agendada',
            '1 publicação(ões) planejada(s) esperando o link de afiliado', 'Aguardando link (1)', 'Cafeteira Sem Link', 'Enviadas e encerradas', 'Mixer Enviado', 'Enviada', 'Grupo Ofertas A'] as $text) {
            self::assertStringContainsString($text, $page);
        }
        self::assertStringNotContainsString('Produto da Conta B', $page);
        // Item da fila aguardando link sem estar em seleção nenhuma: aparece para preparar o link, só na própria conta.
        $pageB = $this->page($this->signIn('bia@loja-b.test', self::PASSWORD));
        self::assertStringNotContainsString('Cafeteira Sem Link', $pageB);
        self::assertStringContainsString('Produto da Conta B', $pageB);
        self::assertStringNotContainsString('@g.us', $page);
        self::assertStringNotContainsString('meli.la', $page, 'Links de afiliado não aparecem na fila.');
        self::assertDoesNotMatchRegularExpression('/MLB3000\d\d/', $page);
    }

    public function testApproveSkipAndBotToggle(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        self::assertSame('/fila?ok=aprovado', $this->post($ana, '/fila/itens/' . $this->keys['pendA'] . '/aprovar')->getHeaderLine('Location'));
        self::assertSame([['scheduled', (string) $this->ana]], $this->rows('SELECT status, decided_by_user_id FROM dispatch_queue WHERE public_key = ?', [$this->keys['pendA']]));
        self::assertSame('/fila?erro=item_not_found', $this->post($ana, '/fila/itens/' . $this->keys['pendA'] . '/aprovar')->getHeaderLine('Location'), 'Já decidido.');

        self::assertSame('/fila?ok=pulado', $this->post($ana, '/fila/itens/' . $this->keys['schedA'] . '/pular')->getHeaderLine('Location'));
        self::assertSame('/fila?ok=pulado', $this->post($ana, '/fila/itens/' . $this->keys['awaitA'] . '/pular')->getHeaderLine('Location'));
        self::assertSame('/fila?erro=item_not_found', $this->post($ana, '/fila/itens/' . $this->keys['sentA'] . '/pular')->getHeaderLine('Location'), 'Enviado não se pula.');

        // Ativar pela Fila passa pelo mesmo checklist dos Primeiros passos: conta incompleta não ativa.
        self::assertSame('/comecar?pendente=mercado_livre', $this->post($ana, '/fila/bot/ativar')->getHeaderLine('Location'));
        self::assertSame([['paused']], $this->rows('SELECT bot_status FROM installations WHERE id = ?', [$this->a->id->value]));
        $this->db->prepare("UPDATE installations SET bot_status = 'active' WHERE id = ?")->execute([$this->a->id->value]);
        self::assertStringContainsString('Pausar bot', $this->page($ana));
        self::assertSame('/fila?ok=bot_pausado', $this->post($ana, '/fila/bot/pausar')->getHeaderLine('Location'));
        self::assertSame([['paused']], $this->rows('SELECT bot_status FROM installations WHERE id = ?', [$this->a->id->value]));
        // Pausar não apaga nada.
        self::assertSame('5', (string) $this->db->query('SELECT COUNT(*) FROM dispatch_queue')->fetchColumn());
        self::assertSame([['paused']], $this->rows('SELECT bot_status FROM installations WHERE id = ?', [$this->b->id->value]), 'Só a própria conta muda.');
    }

    public function testAccountsCannotDecideOrSeeEachOthersItems(): void
    {
        $bia = $this->signIn('bia@loja-b.test', self::PASSWORD);
        foreach (['pendA' => 'aprovar', 'schedA' => 'pular', 'awaitA' => 'pular'] as $item => $action) {
            self::assertSame('/fila?erro=item_not_found', $this->post($bia, '/fila/itens/' . $this->keys[$item] . '/' . $action)->getHeaderLine('Location'));
        }
        self::assertSame([['pending_approval'], ['scheduled'], ['awaiting_affiliate_link'], ['sent']], $this->rows('SELECT status FROM dispatch_queue WHERE installation_id = ? ORDER BY id', [$this->a->id->value]));
        $page = $this->page($bia);
        self::assertStringContainsString('Produto da Conta B', $page);
        foreach (['Air Fryer Aprovar', 'Panela Agendada', 'Grupo Ofertas A', $this->keys['pendA']] as $secret) {
            self::assertStringNotContainsString($secret, $page);
        }
    }

    public function testCsrfAndSessionAreRequired(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $csrfB = $this->csrfFor($this->signIn('bia@loja-b.test', self::PASSWORD), '/fila');
        foreach (['/fila/itens/' . $this->keys['pendA'] . '/aprovar', '/fila/itens/' . $this->keys['pendA'] . '/pular', '/fila/bot/ativar', '/fila/bot/pausar'] as $path) {
            self::assertSame(400, $this->httpPost($path, [], ['sbm_session' => $ana])->getStatusCode(), $path);
            self::assertSame(400, $this->httpPost($path, ['_csrf' => $csrfB], ['sbm_session' => $ana])->getStatusCode(), $path);
            self::assertSame('/entrar', $this->httpPost($path, ['_csrf' => 'x'])->getHeaderLine('Location'), $path);
        }
        self::assertSame([['pending_approval']], $this->rows('SELECT status FROM dispatch_queue WHERE public_key = ?', [$this->keys['pendA']]));
        self::assertSame([['paused']], $this->rows('SELECT bot_status FROM installations WHERE id = ?', [$this->a->id->value]));
    }

    private function destination(Installation $inst, int $user, string $name): int
    {
        $niche = (int) $this->db->query("SELECT id FROM niches WHERE slug = 'casa-cozinha'")->fetchColumn();
        $sub = (int) $this->db->query("SELECT id FROM subniches WHERE slug = 'air-fryers'")->fetchColumn();
        (new AccountNicheRepository($this->db))->save($inst->id, $niche, [$sub], NicheFilters::defaults(), $user, new \DateTimeImmutable());
        $this->db->prepare("INSERT INTO destinations (installation_id, public_key, type, provider_ref, name, niche_id, mode, interval_minutes, user_paused, status, eligibility,
                tech_present, tech_is_channel, tech_we_are_admin, tech_has_invite_link, tech_join_approval, public_declared, media_registered_declared, created_at, created_by_user_id, updated_at)
            VALUES (?, ?, 'group', ?, ?, ?, 'manual', 60, 0, 'active', 'group_declared_public', 1, 0, 1, 1, 0, 1, 1, NOW(3), ?, NOW(3))")
            ->execute([$inst->id->value, bin2hex(random_bytes(10)), '1203630000000' . $inst->id->value . '@g.us', $name, $niche, $user]);

        return (int) $this->db->lastInsertId();
    }

    private function link(Installation $inst, int $user, string $product, string $url): int
    {
        $this->db->prepare("INSERT INTO affiliate_links (installation_id, site_id, ml_product_id, original_url, affiliate_url, affiliate_url_sha256, source, status, received_at, confirmed_at, confirmed_by_user_id, created_at, updated_at)
            VALUES (?, 'MLB', ?, 'https://www.mercadolivre.com.br/p/x', ?, SHA2(?, 256), 'manual_batch', 'active', NOW(3), NOW(3), ?, NOW(3), NOW(3))")
            ->execute([$inst->id->value, $product, $url, $url, $user]);

        return (int) $this->db->lastInsertId();
    }

    private function item(string $name, Installation $inst, int $dest, string $product, string $status, ?int $link): void
    {
        $key = bin2hex(random_bytes(10));
        $this->keys[$name] = $key;
        $niche = (int) $this->db->query("SELECT id FROM niches WHERE slug = 'casa-cozinha'")->fetchColumn();
        $sub = (int) $this->db->query("SELECT id FROM subniches WHERE slug = 'air-fryers'")->fetchColumn();
        $this->db->prepare("INSERT INTO dispatch_queue (installation_id, public_key, destination_id, niche_id, subniche_id, ml_product_id, status, affiliate_link_id, scheduled_for,
                planned_price, planned_original, planned_discount, message_json, sent_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(3) + INTERVAL 1 HOUR, 199.90, 249.90, 20, ?, ?, NOW(3), NOW(3))")
            ->execute([$inst->id->value, $key, $dest, $niche, $sub, $product, $status, $link, $status === 'sent' ? '{}' : null, $status === 'sent' ? date('Y-m-d H:i:s') : null]);
    }

    private function post(string $session, string $path): ResponseInterface
    {
        return $this->httpPost($path, ['_csrf' => $this->csrfFor($session, '/fila')], ['sbm_session' => $session]);
    }

    private function page(string $session): string
    {
        return (string) $this->httpGet('/fila', ['sbm_session' => $session])->getBody();
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
