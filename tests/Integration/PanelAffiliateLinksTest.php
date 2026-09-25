<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use DI\Container;
use GuzzleHttp\Client;
use Monolog\Handler\TestHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Sinergia\Application\Affiliate\BatchMatcher;
use Sinergia\Application\Affiliate\BatchRejected;
use Sinergia\Application\Affiliate\ManualBatchAffiliateLinkProvider;
use Sinergia\Application\Affiliate\MeliLaShortLinkFormat;
use Sinergia\Application\Affiliate\ProductToLink;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\AffiliateLinkRepository;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Kernel;
use Sinergia\Shared\Clock\SystemClock;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Tests\Support\PanelRequests;
use Sinergia\Tests\Support\TestIdFormat;
use Sinergia\Web\HttpApp;

/**
 * Etapa 7: links de afiliado em lote (manual_batch). Nenhum HTTP de saída é permitido: os clientes do ML e do
 * WhatsApp são trocados por clientes que registram e RECUSAM qualquer requisição.
 */
final class PanelAffiliateLinksTest extends DatabaseTestCase
{
    use PanelRequests;

    private const string PASSWORD = 'senha-do-painel-123';
    private const string L1 = 'https://meli.la/2sWvfQU';   // amostra real
    private const string L2 = 'https://meli.la/1rZgSMj';   // amostra real
    private const string L3 = 'https://meli.la/9QzXk2p';   // fictício, mesmo formato

    private \PDO $db;
    private Installation $a;
    private Installation $b;
    private int $ana;
    private int $bia;
    /** @var list<string> */
    private array $outbound = [];
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

        foreach (['MLB100001' => 'Air Fryer 4L', 'MLB100002' => 'Panela de Pressão 6L', 'MLB100003' => 'Jogo de Panelas', 'MLB100004' => 'Cafeteira'] as $id => $name) {
            $this->db->prepare("INSERT INTO ml_products (ml_product_id, site_id, status, name, domain_id, permalink, picture_url, pictures_count, fetched_at)
                VALUES (?, 'MLB', 'ok', ?, 'MLB-X', ?, 'https://http2.mlstatic.com/x.jpg', 1, NOW(3))")
                ->execute([$id, $name, 'https://www.mercadolivre.com.br/produto-teste/p/' . $id]);
        }
        $this->candidates($this->a, ['MLB100001', 'MLB100002', 'MLB100003']);
        $this->candidates($this->b, ['MLB100001', 'MLB100004']);

        $env = array_filter(array_merge($_ENV, getenv()), 'is_string');
        $container = Kernel::container(Config::fromArray([
            'APP_ENV' => 'test',
            'APP_KEY' => SecretBox::generateKeyBase64(),
            'DB_HOST' => (string) ($env['TEST_DB_HOST'] ?? ''),
            'DB_PORT' => (string) ($env['TEST_DB_PORT'] ?? '3306'),
            'DB_DATABASE' => (string) ($env['TEST_DB_DATABASE'] ?? ''),
            'DB_USERNAME' => (string) ($env['TEST_DB_USERNAME'] ?? ''),
            'DB_PASSWORD' => (string) ($env['TEST_DB_PASSWORD'] ?? ''),
        ]), dirname(__DIR__, 2));
        self::assertInstanceOf(Container::class, $container);
        $refuse = new Client(['handler' => function ($request) {
            $this->outbound[] = (string) $request->getUri();
            throw new \LogicException('Nenhuma requisição de saída é permitida neste fluxo.');
        }]);
        $container->set('ml.http', $refuse);
        $container->set('whatsapp.http', $refuse);
        $container->set(LoggerInterface::class, LoggerFactory::create('local', new TestHandler()));
        $this->app = HttpApp::create($container);
    }

    protected function tearDown(): void
    {
        self::assertSame([], $this->outbound, 'A aplicação não pode abrir nenhum link nem fazer requisição de saída.');
        parent::tearDown();
    }

    protected function panelApp(): App
    {
        return $this->app;
    }

    public function testCleanBatchFlowStoresLinksExactlyAsReceived(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        self::assertStringContainsString('Aguardando link (3)', $this->page($ana));

        self::assertSame('/fila?ok=exportado#afiliados', $this->post($ana, '/fila/links/exportar')->getHeaderLine('Location'));
        $page = $this->page($ana);
        $key = $this->batchKey($page);
        // Exporta as URLs originais, na ordem, sem alteração.
        self::assertStringContainsString(
            "https://www.mercadolivre.com.br/produto-teste/p/MLB100001\nhttps://www.mercadolivre.com.br/produto-teste/p/MLB100002\nhttps://www.mercadolivre.com.br/produto-teste/p/MLB100003</textarea>",
            $page,
        );

        // Colar com espaços nas pontas e CRLF: a linha é guardada exata; o link, sem os espaços em volta.
        $this->post($ana, "/fila/links/$key/colar", ['links' => "  " . self::L1 . " \r\n" . self::L2 . "\r\n" . self::L3 . "\r\n"]);
        self::assertSame(0, $this->tableCount('affiliate_links'), 'Pré-visualização não grava na biblioteca.');
        $preview = $this->page($ana);
        self::assertStringContainsString('Fraca — só pela posição', $preview);
        self::assertStringContainsString('Os links foram propostos pela ordem', $preview);
        self::assertSame(3, preg_match_all('#<option value="[a-f0-9]{20}" selected>#', $preview));

        $response = $this->confirm($ana, $key, $this->proposed($preview));
        self::assertSame('/fila?ok=confirmado&resumo=3-0-0-0#afiliados', $response->getHeaderLine('Location'));

        $links = $this->rows('SELECT ml_product_id, affiliate_url, HEX(affiliate_url), affiliate_url_sha256, source, status, confirmed_by_user_id, original_url FROM affiliate_links WHERE installation_id = ? ORDER BY ml_product_id', [$this->a->id->value]);
        self::assertSame(['MLB100001', 'MLB100002', 'MLB100003'], array_column($links, 0));
        self::assertSame([self::L1, self::L2, self::L3], array_column($links, 1));
        self::assertSame([strtoupper(bin2hex(self::L1)), strtoupper(bin2hex(self::L2)), strtoupper(bin2hex(self::L3))], array_column($links, 2), 'Byte a byte.');
        self::assertSame([hash('sha256', self::L1), hash('sha256', self::L2), hash('sha256', self::L3)], array_column($links, 3));
        self::assertSame(['manual_batch'], array_values(array_unique(array_column($links, 4))));
        self::assertSame(['active'], array_values(array_unique(array_column($links, 5))));
        self::assertSame([(string) $this->ana], array_values(array_unique(array_column($links, 6))));
        self::assertSame('https://www.mercadolivre.com.br/produto-teste/p/MLB100001', $links[0][7]);

        $item = $this->rows('SELECT received_raw, received_position, format_status, match_evidence, match_status FROM affiliate_link_batch_items WHERE position = 1')[0];
        self::assertSame(['  ' . self::L1 . ' ', '1', 'valid', 'position_only', 'confirmed'], $item);
        self::assertSame([['confirmed', '3', '3']], $this->rows('SELECT status, item_count, confirmed_count FROM affiliate_link_batches'));
        self::assertStringContainsString('Aguardando link (0)', $this->page($ana));
    }

    public function testRealSampleCountMismatchRequiresManualAssociationAndAllowsPartialConfirmation(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->export($ana);
        // Amostra real: 2 links para 3 produtos.
        $this->post($ana, "/fila/links/$key/colar", ['links' => self::L1 . "\n" . self::L2]);
        $page = $this->page($ana);
        self::assertStringContainsString('Nada foi associado automaticamente: a quantidade de links é diferente da quantidade de produtos', $page);
        self::assertSame(0, preg_match_all('# selected>#', $page), 'Nenhuma associação pré-selecionada.');

        // O cliente associa manualmente (inclusive fora da ordem) e confirma só dois.
        $items = $this->itemKeys($key);
        $this->confirm($ana, $key, [1 => $items[2], 2 => $items[1]]);
        self::assertSame([['MLB100001', self::L2], ['MLB100002', self::L1]], $this->rows('SELECT ml_product_id, affiliate_url FROM affiliate_links ORDER BY ml_product_id'));
        self::assertSame([['partially_confirmed', '2']], $this->rows('SELECT status, confirmed_count FROM affiliate_link_batches'));
        self::assertSame(['manual', 'manual', 'none'], array_column($this->rows('SELECT match_evidence FROM affiliate_link_batch_items ORDER BY position'), 0));
        self::assertStringContainsString('Aguardando link (1)', $this->page($ana));
        // Lote concluído não aceita nova confirmação.
        self::assertSame('/fila?erro=batch_closed#afiliados', $this->confirm($ana, $key, [1 => $items[3]])->getHeaderLine('Location'));
    }

    public function testAnomaliesAreNeverAssociatedSilently(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->export($ana);
        $cases = [
            self::L1 . "\n\n" . self::L2 . "\n" . self::L3 => 'há linha vazia no meio',
            self::L1 . "\n" . self::L1 . "\n" . self::L3 => 'há link repetido',
            self::L1 . "\nhttps://mercadolivre.com/sec/1AbCdEf\n" . self::L3 => 'há link de domínio não aceito',
        ];
        foreach ($cases as $pasted => $message) {
            $this->post($ana, "/fila/links/$key/colar", ['links' => $pasted]);
            $page = $this->page($ana);
            self::assertStringContainsString($message, $page);
            self::assertSame(0, preg_match_all('# selected>#', $page));
        }
        // Linha inválida não pode ser associada, nem forçando pelo formulário.
        $items = $this->itemKeys($key);
        self::assertSame('/fila?erro=invalid_line#afiliados', $this->confirm($ana, $key, [2 => $items[2]])->getHeaderLine('Location'));
        self::assertSame('/fila?erro=nothing_selected#afiliados', $this->confirm($ana, $key, [1 => ''])->getHeaderLine('Location'));
        self::assertSame('/fila?erro=item_twice#afiliados', $this->confirm($ana, $key, [1 => $items[1], 3 => $items[1]])->getHeaderLine('Location'));
        self::assertSame(0, $this->tableCount('affiliate_links'));
    }

    public function testCleanBatchPartialConfirmation(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->export($ana);
        $this->post($ana, "/fila/links/$key/colar", ['links' => implode("\n", [self::L1, self::L2, self::L3])]);
        $proposed = $this->proposed($this->page($ana));
        $proposed[3] = '';   // cliente desmarca o terceiro

        self::assertStringContainsString('resumo=2-0-0-1', $this->confirm($ana, $key, $proposed)->getHeaderLine('Location'));
        self::assertSame(['MLB100001', 'MLB100002'], array_column($this->rows('SELECT ml_product_id FROM affiliate_links ORDER BY ml_product_id'), 0));
        self::assertSame([['unmatched', null]], $this->rows('SELECT match_status, received_raw FROM affiliate_link_batch_items WHERE position = 3'));
        self::assertStringContainsString('Aguardando link (1)', $this->page($ana));
    }

    public function testReplacementKeepsHistoryAndIdenticalLinkIsReused(): void
    {
        $provider = $this->provider();
        $this->linkAll($provider, [self::L1, self::L2, self::L3]);

        // Produto que já tem link continua sendo reaproveitado (não volta a "aguardar").
        $result = $provider->request($this->a->id, [new ProductToLink('MLB100001', 'x', 'x'), new ProductToLink('MLB100004', 'y', 'y')]);
        self::assertSame(self::L1, $result->links['MLB100001']->affiliateUrl);
        self::assertSame(['MLB100004'], $result->pending);
        self::assertSame(0, (new AffiliateLinkRepository($this->db))->countAwaitingLink($this->a->id));

        // Novo lote para produtos que já têm link (ex.: link a trocar): mesmo link = reuso; outro = substituição.
        $repo = new AffiliateLinkRepository($this->db);
        $batch = $repo->createBatch($this->a->id, $this->ana, [new ProductToLink('MLB100001', 'https://www.mercadolivre.com.br/produto-teste/p/MLB100001', 'A'), new ProductToLink('MLB100002', 'https://www.mercadolivre.com.br/produto-teste/p/MLB100002', 'B')], new \DateTimeImmutable(), new \DateTimeImmutable('+1 day'));
        $provider->previewImport($this->a->id, $batch->key, self::L1 . "\nhttps://meli.la/NovoLink7");
        $view = $repo->batch($this->a->id, $batch->key);
        $report = $provider->confirmImport($this->a->id, $batch->key, [1 => $view->items[0]->itemKey, 2 => $view->items[1]->itemKey], $this->ana);

        self::assertSame([1, 1, 1], [$report->created, $report->replaced, $report->reused]);
        self::assertSame(
            [['MLB100001', self::L1, 'active'], ['MLB100002', self::L2, 'replaced'], ['MLB100002', 'https://meli.la/NovoLink7', 'active'], ['MLB100003', self::L3, 'active']],
            $this->rows('SELECT ml_product_id, affiliate_url, status FROM affiliate_links ORDER BY ml_product_id, id'),
        );
        self::assertSame([['1']], $this->rows("SELECT COUNT(*) FROM affiliate_links WHERE ml_product_id = 'MLB100002' AND status = 'active'"));
        // O banco recusa um segundo link ativo para o mesmo produto da mesma conta.
        try {
            $this->db->exec("UPDATE affiliate_links SET status = 'active' WHERE status = 'replaced'");
            self::fail('Dois links ativos não podem existir.');
        } catch (\PDOException) {
            self::assertTrue(true);
        }
    }

    public function testConflictingProductIdentityIsRejectedEvenManually(): void
    {
        $repo = new AffiliateLinkRepository($this->db);
        $provider = new ManualBatchAffiliateLinkProvider($repo, new BatchMatcher([new MeliLaShortLinkFormat(), new TestIdFormat()]), new SystemClock(), LoggerFactory::create('local', new TestHandler()));
        $batch = $provider->exportBatch($this->a->id, $this->ana, $repo->productsAwaitingLink($this->a->id, 50));
        $provider->previewImport($this->a->id, $batch->key, "https://ids.example.test/MLB100002/a\n" . self::L2 . "\n" . self::L3);
        $view = $repo->batch($this->a->id, $batch->key);
        self::assertSame(['id_conflict'], $view->anomalies);

        try {
            $provider->confirmImport($this->a->id, $batch->key, [1 => $view->items[0]->itemKey], $this->ana);
            self::fail('Conflito de identidade não pode ser associado.');
        } catch (BatchRejected $e) {
            self::assertSame(BatchRejected::ID_CONFLICT, $e->reason);
        }
        // O mesmo link associado ao produto que ele indica é aceito com evidência forte.
        $provider->confirmImport($this->a->id, $batch->key, [1 => $view->items[1]->itemKey], $this->ana);
        self::assertSame([['MLB100002', 'product_id']], $this->rows('SELECT ml_product_id, match_evidence FROM affiliate_link_batch_items WHERE match_status = \'confirmed\''));
    }

    public function testAccountsAreIsolated(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $bia = $this->signIn('bia@loja-b.test', self::PASSWORD);
        $key = $this->export($ana);
        $this->post($ana, "/fila/links/$key/colar", ['links' => implode("\n", [self::L1, self::L2, self::L3])]);
        $itemsA = $this->itemKeys($key);
        $before = $this->rows('SELECT * FROM affiliate_link_batch_items ORDER BY id');

        // B não vê o lote de A e não consegue colar, confirmar nem descartar nele.
        $pageB = $this->page($bia);
        self::assertStringNotContainsString($key, $pageB);
        self::assertStringNotContainsString('Panela de Pressão 6L', $pageB, 'Produto só de A (no lote de A) não aparece para B.');
        foreach (['colar' => ['links' => self::L1], 'confirmar' => ['associar' => [1 => $itemsA[1]]], 'descartar' => []] as $op => $body) {
            self::assertSame('/fila?erro=batch_not_found#afiliados', $this->post($bia, "/fila/links/$key/$op", $body)->getHeaderLine('Location'), $op);
        }
        self::assertSame($before, $this->rows('SELECT * FROM affiliate_link_batch_items ORDER BY id'));

        // A confirma; o link de A nunca serve para B, mesmo para o MESMO produto.
        $this->confirm($ana, $key, $this->proposed($this->page($ana)));
        $provider = $this->provider();
        self::assertArrayHasKey('MLB100001', $provider->findExisting($this->a->id, ['MLB100001']));
        self::assertSame([], $provider->findExisting($this->b->id, ['MLB100001']));
        self::assertStringContainsString('Aguardando link (2)', $this->page($bia));

        // B exporta o próprio lote e confirma um link próprio para o mesmo produto: coexistem, cada um na sua conta.
        $keyB = $this->export($bia);
        $this->post($bia, "/fila/links/$keyB/colar", ['links' => "https://meli.la/ContaB01\nhttps://meli.la/ContaB02"]);
        $this->confirm($bia, $keyB, $this->proposed($this->page($bia)));
        self::assertSame(
            [[(string) $this->a->id->value, 'MLB100001', self::L1], [(string) $this->b->id->value, 'MLB100001', 'https://meli.la/ContaB01']],
            $this->rows("SELECT installation_id, ml_product_id, affiliate_url FROM affiliate_links WHERE ml_product_id = 'MLB100001' ORDER BY installation_id"),
        );
        // B confirmando o lote de A com as chaves de item de A: não encontrado.
        self::assertSame('/fila?erro=batch_not_found#afiliados', $this->confirm($bia, $key, [1 => $itemsA[1]])->getHeaderLine('Location'));
    }

    public function testCsrfAndSessionAreRequired(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->export($ana);
        $csrfB = $this->csrfFor($this->signIn('bia@loja-b.test', self::PASSWORD), '/fila');
        foreach (['/fila/links/exportar', "/fila/links/$key/colar", "/fila/links/$key/confirmar", "/fila/links/$key/descartar"] as $path) {
            self::assertSame(400, $this->httpPost($path, ['links' => self::L1], ['sbm_session' => $ana])->getStatusCode(), $path);
            self::assertSame(400, $this->httpPost($path, ['_csrf' => $csrfB, 'links' => self::L1], ['sbm_session' => $ana])->getStatusCode(), $path);
            self::assertSame('/entrar', $this->httpPost($path, ['_csrf' => 'x'])->getHeaderLine('Location'), $path);
        }
        self::assertSame('/entrar', $this->httpGet('/fila')->getHeaderLine('Location'));
        self::assertSame([['exported']], $this->rows('SELECT status FROM affiliate_link_batches'));
    }

    public function testCancelDiscardsWithoutWriting(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->export($ana);
        $this->post($ana, "/fila/links/$key/colar", ['links' => implode("\n", [self::L1, self::L2, self::L3])]);
        self::assertSame('/fila?ok=descartado#afiliados', $this->post($ana, "/fila/links/$key/descartar")->getHeaderLine('Location'));
        self::assertSame(0, $this->tableCount('affiliate_links'));
        self::assertSame('/fila?erro=batch_closed#afiliados', $this->post($ana, "/fila/links/$key/colar", ['links' => self::L1])->getHeaderLine('Location'));
    }

    public function testAffiliateCodeNeverOpensLinks(): void
    {
        $dirs = ['src/Application/Affiliate', 'src/Infrastructure/Persistence/AffiliateLinkRepository.php', 'src/Web/Action/Panel/AffiliateBatchAction.php', 'src/Web/Action/Panel/QueuePageAction.php'];
        $root = dirname(__DIR__, 2) . '/';
        foreach ($dirs as $path) {
            $files = is_dir($root . $path) ? glob($root . $path . '/*.php') : [$root . $path];
            foreach ($files ?: [] as $file) {
                $code = (string) file_get_contents($file);
                foreach (['sendRequest', 'GuzzleHttp', 'curl_', 'file_get_contents', 'fopen', 'get_headers', 'fsockopen', 'stream_socket', 'ClientInterface', 'MercadoLivreClient', 'WhatsAppProvider'] as $forbidden) {
                    self::assertStringNotContainsString($forbidden, $code, basename($file) . ' não pode abrir links (' . $forbidden . ').');
                }
            }
        }
    }

    private function provider(): ManualBatchAffiliateLinkProvider
    {
        return new ManualBatchAffiliateLinkProvider(new AffiliateLinkRepository($this->db), new BatchMatcher([new MeliLaShortLinkFormat()]), new SystemClock(), LoggerFactory::create('local', new TestHandler()));
    }

    /** @param list<string> $links */
    private function linkAll(ManualBatchAffiliateLinkProvider $provider, array $links): void
    {
        $repo = new AffiliateLinkRepository($this->db);
        $batch = $provider->exportBatch($this->a->id, $this->ana, $repo->productsAwaitingLink($this->a->id, 50));
        $provider->previewImport($this->a->id, $batch->key, implode("\n", $links));
        $view = $repo->batch($this->a->id, $batch->key);
        $associations = [];
        foreach ($view->items as $i => $item) {
            $associations[$i + 1] = $item->itemKey;
        }
        $provider->confirmImport($this->a->id, $batch->key, $associations, $this->ana);
    }

    /** @param list<string> $products */
    private function candidates(Installation $installation, array $products): void
    {
        $this->db->prepare("INSERT INTO offer_selection_runs (installation_id, status, started_at, finished_at) VALUES (?, 'completed', NOW(3), NOW(3))")->execute([$installation->id->value]);
        $run = (int) $this->db->lastInsertId();
        $sub = $this->rows("SELECT s.niche_id, s.id FROM subniches s WHERE s.slug = 'air-fryers'")[0];
        foreach ($products as $i => $product) {
            $this->db->prepare("INSERT INTO account_offer_candidates (installation_id, run_id, ml_product_id, niche_id, subniche_id, ml_category_id, ranking_position, sort_order, item_id, price, original_price, discount_pct, selection_rule)
                VALUES (?, ?, ?, ?, ?, 'MLB456045', ?, ?, 'MLB7000000001', 199.90, 249.90, 20, 'lowest_price')")
                ->execute([$installation->id->value, $run, $product, $sub[0], $sub[1], $i + 1, $i + 1]);
        }
    }

    private function export(string $session): string
    {
        $this->post($session, '/fila/links/exportar');

        return $this->batchKey($this->page($session));
    }

    /** @param array<int, string> $associations */
    private function confirm(string $session, string $key, array $associations): ResponseInterface
    {
        return $this->post($session, "/fila/links/$key/confirmar", ['associar' => $associations]);
    }

    /** @return array<int, string> linha → item_key pré-selecionado na pré-visualização */
    private function proposed(string $html): array
    {
        preg_match_all('#name="associar\[(\d+)\]".*?<option value="([a-f0-9]{20})" selected>#s', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [$_, $line, $item]) {
            $out[(int) $line] = $item;
        }

        return $out;
    }

    /** @return array<int, string> posição → item_key */
    private function itemKeys(string $batchKey): array
    {
        $out = [];
        foreach ($this->rows('SELECT i.position, i.item_key FROM affiliate_link_batch_items i JOIN affiliate_link_batches b ON b.id = i.batch_id AND b.installation_id = i.installation_id WHERE b.public_key = ? ORDER BY i.position', [$batchKey]) as [$pos, $item]) {
            $out[(int) $pos] = (string) $item;
        }

        return $out;
    }

    private function batchKey(string $html): string
    {
        self::assertSame(1, preg_match('#/fila/links/([a-f0-9]{20})/colar#', $html, $m));

        return $m[1];
    }

    /** @param array<string, mixed> $body */
    private function post(string $session, string $path, array $body = []): ResponseInterface
    {
        return $this->httpPost($path, ['_csrf' => $this->csrfFor($session, '/fila')] + $body, ['sbm_session' => $session]);
    }

    private function page(string $session): string
    {
        return (string) $this->httpGet('/fila', ['sbm_session' => $session])->getBody();
    }

    private function tableCount(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
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
