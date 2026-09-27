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
    private Container $container;
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
                ->execute([$id, $name, null]); // a API passou a devolver permalink vazio (etapa 12C)
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
        $this->container = $container;
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
        // Exporta a URL do ANÚNCIO escolhido (item_id) de cada produto, na ordem — o permalink vazio não é usado.
        self::assertStringContainsString(
            "https://produto.mercadolivre.com.br/MLB-7100001\nhttps://produto.mercadolivre.com.br/MLB-7100002\nhttps://produto.mercadolivre.com.br/MLB-7100003</textarea>",
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

        $links = $this->rows('SELECT ml_product_id, affiliate_url, HEX(affiliate_url), affiliate_url_sha256, source, status, confirmed_by_user_id, original_url, offer_item_id FROM affiliate_links WHERE installation_id = ? ORDER BY ml_product_id', [$this->a->id->value]);
        self::assertSame(['MLB100001', 'MLB100002', 'MLB100003'], array_column($links, 0));
        self::assertSame([self::L1, self::L2, self::L3], array_column($links, 1));
        self::assertSame([strtoupper(bin2hex(self::L1)), strtoupper(bin2hex(self::L2)), strtoupper(bin2hex(self::L3))], array_column($links, 2), 'Byte a byte.');
        self::assertSame([hash('sha256', self::L1), hash('sha256', self::L2), hash('sha256', self::L3)], array_column($links, 3));
        self::assertSame(['manual_batch'], array_values(array_unique(array_column($links, 4))));
        self::assertSame(['active'], array_values(array_unique(array_column($links, 5))));
        self::assertSame([(string) $this->ana], array_values(array_unique(array_column($links, 6))));
        self::assertSame('https://produto.mercadolivre.com.br/MLB-7100001', $links[0][7]);
        // Cada link fica vinculado ao anúncio para o qual foi gerado.
        self::assertSame(['MLB7100001', 'MLB7100002', 'MLB7100003'], array_column($links, 8));
        self::assertSame([['MLB7100001'], ['MLB7100002'], ['MLB7100003']], $this->rows('SELECT offer_item_id FROM affiliate_link_batch_items ORDER BY position'));

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
        $result = $provider->request($this->a->id, [new ProductToLink('MLB100001', 'MLB7100001', 'x'), new ProductToLink('MLB100004', 'MLB7100004', 'y')]);
        self::assertSame(self::L1, $result->links['MLB100001']->affiliateUrl);
        self::assertSame(['MLB100004'], $result->pending);
        self::assertSame(0, (new AffiliateLinkRepository($this->db))->countAwaitingLink($this->a->id));

        // Novo lote para produtos que já têm link (ex.: link a trocar): mesmo link = reuso; outro = substituição.
        $repo = new AffiliateLinkRepository($this->db);
        $batch = $repo->createBatch($this->a->id, $this->ana, [new ProductToLink('MLB100001', 'MLB7100001', 'A'), new ProductToLink('MLB100002', 'MLB7100002', 'B')], new \DateTimeImmutable(), new \DateTimeImmutable('+1 day'));
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

    public function testBatchIsCappedAtThirtyAndTheButtonShowsTheCount(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->thirtyOneCandidates();

        self::assertStringContainsString('Copiar URLs (30 produtos)', $this->page($ana));
        $key = $this->export($ana);

        self::assertCount(30, explode("\n", $this->exportTextOf($key)));
        self::assertSame([['30']], $this->rows('SELECT item_count FROM affiliate_link_batches WHERE public_key = ?', [$key]));
        self::assertStringContainsString('1. Copie as URLs (30 produtos)', $this->page($ana));
    }

    public function testThirtyLinksInOrderAreProposedOneToOneAndAllPreselected(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->thirtyOneCandidates();
        $key = $this->export($ana);

        $this->post($ana, "/fila/links/$key/colar", ['links' => implode("\n", self::thirtyLinks())]);
        $page = $this->page($ana);

        $keys = $this->itemKeys($key);
        $expected = [];
        foreach (range(1, 30) as $n) {
            $expected[$n] = $keys[$n];   // link n → produto n
        }
        self::assertSame($expected, $this->proposed($page));
        self::assertSame(range(1, 30), $this->checkedLines($page));
        self::assertStringContainsString('✓ Selecionar todos', $page);
        self::assertStringContainsString('Desmarcar todos', $page);
        self::assertStringContainsString('de 30 associações selecionadas', $page);
        self::assertStringContainsString('data-inicial="30"', $page);
        self::assertStringContainsString('Confirmar <span class="bulk-count" data-inicial="30"></span> associações', $page);
        self::assertSame(0, $this->tableCount('affiliate_links'), 'A prévia nunca grava.');
    }

    public function testProgramRefusalsInTheirPositionsBecomeRejectedAndTheOtherLinksConfirmOneToOne(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->thirtyOneCandidates();
        $key = $this->export($ana);
        $refused = [8, 13, 28];
        $lines = self::thirtyLinks();
        foreach ($refused as $n) {
            $lines[$n - 1] = '⚠️ Este URL não é permitido pelo Programa.';   // como vem da caixa do Gerador (posição preservada)
        }

        $this->post($ana, "/fila/links/$key/colar", ['links' => implode("\n", $lines)]);
        $page = $this->page($ana);

        self::assertStringContainsString('<strong>27</strong> link(s) válido(s) · <strong>3</strong> produto(s) recusado(s) pelo Mercado Livre', $page);
        self::assertStringNotContainsString('Nada foi associado automaticamente', $page);
        $valid = array_values(array_diff(range(1, 30), $refused));
        self::assertSame($valid, $this->checkedLines($page), 'Selecionar todos = as 27 válidas.');
        self::assertSame($valid, array_keys($this->proposed($page)));
        self::assertStringContainsString('de 27 associações selecionadas', $page);
        self::assertStringContainsString('data-inicial="27"', $page);
        foreach ($refused as $n) {
            self::assertStringNotContainsString('name="incluir[' . $n . ']"', $page, 'Recusado não tem caixa.');
            self::assertStringNotContainsString('name="associar[' . $n . ']"', $page);
            self::assertStringContainsString($n . '. Produto lote ' . $n . ' — não permitido pelo Programa de Afiliados', $page);
        }
        self::assertSame(0, $this->tableCount('affiliate_links'), 'A prévia nunca grava.');

        // Confirmar as 27 selecionadas.
        $response = $this->post($ana, "/fila/links/$key/confirmar", ['com_selecao' => '1', 'associar' => $this->proposed($page), 'incluir' => array_fill_keys($valid, '1')]);
        self::assertSame('/fila?ok=confirmado&resumo=27-0-0-0&recusados=3#afiliados', $response->getHeaderLine('Location'));

        // Só os 27 válidos gravados, cada um no produto da SUA posição, byte a byte.
        $rows = $this->rows('SELECT i.position, l.ml_product_id, l.offer_item_id, l.affiliate_url, HEX(l.affiliate_url) FROM affiliate_links l JOIN affiliate_link_batch_items i ON i.id = l.batch_item_id ORDER BY i.position');
        self::assertSame(array_map('strval', $valid), array_column($rows, 0));
        foreach ($rows as [$position, $product, $offer, $url, $hex]) {
            self::assertSame(sprintf('MLB2000%02d', (int) $position), $product);
            self::assertSame(sprintf('MLB72000%02d', (int) $position), $offer);
            self::assertSame(sprintf('https://meli.la/Lote30n%02d', (int) $position), $url);
            self::assertSame(strtoupper(bin2hex($url)), $hex);
        }
        // Recusados registrados com o motivo; lote concluído (nada pendente).
        self::assertSame(
            [['8', 'MLB200008', 'rejected', 'affiliate_program_rejected'], ['13', 'MLB200013', 'rejected', 'affiliate_program_rejected'], ['28', 'MLB200028', 'rejected', 'affiliate_program_rejected']],
            $this->rows("SELECT position, ml_product_id, match_status, reason FROM affiliate_link_batch_items WHERE match_status = 'rejected' ORDER BY position"),
        );
        self::assertSame([['confirmed', '27']], $this->rows('SELECT status, confirmed_count FROM affiliate_link_batches WHERE public_key = ?', [$key]));
        self::assertStringContainsString('3 produto(s) não permitido(s) pelo Programa de Afiliados foram retirados dos próximos lotes.', (string) $this->httpGet('/fila', ['sbm_session' => $ana], ['ok' => 'confirmado', 'resumo' => '27-0-0-0', 'recusados' => '3'])->getBody());

        // O próximo lote NÃO traz os recusados: sobra só o 31º produto (sem ciclo infinito).
        self::assertStringContainsString('Copiar URLs (1 produtos)', $this->page($ana));
        $next = $this->export($ana);
        self::assertSame('https://produto.mercadolivre.com.br/MLB-7200031', $this->exportTextOf($next));
        $awaiting = array_map(static fn ($p): string => $p->productId, $this->container->get(AffiliateLinkRepository::class)->productsAwaitingLink($this->a->id, 50));
        self::assertSame([], array_intersect(['MLB200008', 'MLB200013', 'MLB200028'], $awaiting));
    }

    public function testDeselectAllAndSelectAll(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->thirtyOneCandidates();
        $key = $this->export($ana);
        $this->post($ana, "/fila/links/$key/colar", ['links' => implode("\n", self::thirtyLinks())]);

        $none = (string) $this->httpGet('/fila', ['sbm_session' => $ana], ['selecao' => 'nenhum'])->getBody();
        self::assertSame([], $this->checkedLines($none));
        self::assertStringContainsString('data-inicial="0"', $none);
        self::assertCount(30, $this->proposed($none), 'Desmarcar não apaga as propostas, só a seleção.');

        $all = (string) $this->httpGet('/fila', ['sbm_session' => $ana], ['selecao' => 'todos'])->getBody();
        self::assertSame(range(1, 30), $this->checkedLines($all));
    }

    public function testBulkConfirmationKeepsIdentityOfferAndExactLinkAndHonoursAnUncheckedLine(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->thirtyOneCandidates();
        $key = $this->export($ana);
        $links = self::thirtyLinks();
        $links[0] = '  ' . $links[0] . ' ';   // espaços nas pontas: o link é guardado sem eles, nada mais muda
        $this->post($ana, "/fila/links/$key/colar", ['links' => implode("\r\n", $links)]);
        $proposed = $this->proposed($this->page($ana));

        // Todas marcadas, menos a linha 7 (desmarcada individualmente antes de confirmar).
        $included = array_fill_keys(array_diff(range(1, 30), [7]), '1');
        $response = $this->post($ana, "/fila/links/$key/confirmar", ['com_selecao' => '1', 'associar' => $proposed, 'incluir' => $included]);

        self::assertSame('/fila?ok=confirmado&resumo=29-0-0-1#afiliados', $response->getHeaderLine('Location'));
        $rows = $this->rows(
            'SELECT i.position, l.ml_product_id, l.offer_item_id, l.affiliate_url, HEX(l.affiliate_url), l.original_url
             FROM affiliate_links l JOIN affiliate_link_batch_items i ON i.id = l.batch_item_id ORDER BY i.position'
        );
        self::assertCount(29, $rows);
        self::assertNotContains('7', array_column($rows, 0), 'Linha desmarcada não entra.');
        foreach ($rows as [$position, $product, $offer, $url, $hex, $original]) {
            $n = (int) $position;
            self::assertSame(sprintf('MLB2000%02d', $n), $product);
            self::assertSame(sprintf('MLB72000%02d', $n), $offer);
            self::assertSame(sprintf('https://meli.la/Lote30n%02d', $n), $url, 'Link exatamente como colado.');
            self::assertSame(strtoupper(bin2hex($url)), $hex);
            self::assertSame(sprintf('https://produto.mercadolivre.com.br/MLB-72000%02d', $n), $original);
        }
        self::assertSame([['0']], $this->rows("SELECT COUNT(*) FROM affiliate_links WHERE ml_product_id = 'MLB200007'"));
        self::assertSame([['partially_confirmed', '29']], $this->rows('SELECT status, confirmed_count FROM affiliate_link_batches WHERE public_key = ?', [$key]));
    }

    public function testFewerLinksThanProductsAreNeverAssociatedByPositionSilently(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->thirtyOneCandidates();
        $key = $this->export($ana);

        // O Gerador recusou 2 URLs ("Este URL não é permitido pelo Programa."): vieram 28 links para 30 produtos.
        $this->post($ana, "/fila/links/$key/colar", ['links' => implode("\n", array_slice(self::thirtyLinks(), 0, 28))]);
        $page = $this->page($ana);

        self::assertStringContainsString('⚠️ Foram encontrados 28 links para um lote de 30 produtos. Confira as associações antes de confirmar.', $page);
        self::assertSame([], $this->proposed($page), 'Nada proposto por posição.');
        self::assertSame([], $this->checkedLines($page), 'Nada marcado.');
        self::assertStringContainsString('data-inicial="0"', $page);
        // Seleção manual continua possível (select e caixa de cada linha válida).
        self::assertSame(28, preg_match_all('#name="incluir\[\d+\]"#', $page));

        // Confirmar sem escolher produto não grava nada.
        $response = $this->post($ana, "/fila/links/$key/confirmar", ['com_selecao' => '1', 'associar' => array_fill(1, 28, ''), 'incluir' => array_fill(1, 28, '1')]);
        self::assertStringContainsString('erro=nothing_selected', $response->getHeaderLine('Location'));
        self::assertSame(0, $this->tableCount('affiliate_links'));
    }

    public function testInvalidLineIsNeverSelected(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->thirtyOneCandidates();
        $key = $this->export($ana);
        $links = self::thirtyLinks();
        $links[3] = 'texto que não é link';   // lixo de verdade (a recusa do Gerador é tratada à parte, como posição)

        $this->post($ana, "/fila/links/$key/colar", ['links' => implode("\n", $links)]);
        $page = $this->page($ana);

        self::assertStringNotContainsString('name="incluir[4]"', $page, 'Linha inválida não tem caixa ativa.');
        self::assertStringNotContainsString('name="associar[4]"', $page);
        self::assertStringContainsString('Linha 4 não pode ser incluída', $page);
        self::assertSame([], $this->checkedLines($page));
        self::assertSame([], $this->proposed($page), 'Com anomalia nada é proposto por posição.');

        // Mesmo forjando o formulário, o backend recusa a linha inválida.
        $keys = $this->itemKeys($key);
        $response = $this->post($ana, "/fila/links/$key/confirmar", ['com_selecao' => '1', 'associar' => [4 => $keys[4]], 'incluir' => [4 => '1']]);
        self::assertStringContainsString('erro=invalid_line', $response->getHeaderLine('Location'));
        self::assertSame(0, $this->tableCount('affiliate_links'));
    }

    public function testConflictLineIsNeverSelectable(): void
    {
        $this->container->set(BatchMatcher::class, new BatchMatcher([new MeliLaShortLinkFormat(), new TestIdFormat()]));
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->thirtyOneCandidates();
        $key = $this->export($ana);
        $links = self::thirtyLinks();
        $links[1] = 'https://ids.example.test/MLB999999/outro';   // indica OUTRO produto na posição 2

        $this->post($ana, "/fila/links/$key/colar", ['links' => implode("\n", $links)]);
        $page = $this->page($ana);

        self::assertStringNotContainsString('name="incluir[2]"', $page);
        self::assertStringContainsString('Linha 2 não pode ser incluída', $page);
        self::assertSame([], $this->checkedLines($page));

        $keys = $this->itemKeys($key);
        $response = $this->post($ana, "/fila/links/$key/confirmar", ['com_selecao' => '1', 'associar' => [2 => $keys[2]], 'incluir' => [2 => '1']]);
        self::assertStringContainsString('erro=id_conflict', $response->getHeaderLine('Location'));
        self::assertSame(0, $this->tableCount('affiliate_links'));
    }

    public function testSameLinkForAnotherOfferIsNotReusedAndTheBindingFollowsTheNewOffer(): void
    {
        $provider = $this->provider();
        $this->linkAll($provider, [self::L1, self::L2, self::L3]);

        // O mesmo texto de link colado para OUTRO anúncio do produto: nunca reaproveita o vínculo antigo.
        $repo = new AffiliateLinkRepository($this->db);
        $batch = $repo->createBatch($this->a->id, $this->ana, [new ProductToLink('MLB100001', 'MLB7999991', 'A')], new \DateTimeImmutable(), new \DateTimeImmutable('+1 day'));
        $provider->previewImport($this->a->id, $batch->key, self::L1);
        $view = $repo->batch($this->a->id, $batch->key);
        $report = $provider->confirmImport($this->a->id, $batch->key, [1 => $view->items[0]->itemKey], $this->ana);

        self::assertSame([1, 1, 0], [$report->created, $report->replaced, $report->reused]);
        self::assertSame(
            [[self::L1, 'MLB7100001', 'replaced'], [self::L1, 'MLB7999991', 'active']],
            $this->rows("SELECT affiliate_url, offer_item_id, status FROM affiliate_links WHERE ml_product_id = 'MLB100001' ORDER BY id"),
        );
    }

    public function testProductWithoutConfirmedOfferIsBlockedFromExportWithAnExplicitReason(): void
    {
        $provider = $this->provider();
        $blocked = new ProductToLink('MLB100001', null, 'Air Fryer 4L');

        try {
            $provider->exportBatch($this->a->id, $this->ana, [$blocked]);
            self::fail('Sem anúncio confirmado não pode haver lote.');
        } catch (BatchRejected $e) {
            self::assertSame(BatchRejected::NO_CONFIRMED_OFFER, $e->reason);
        }
        self::assertSame(0, $this->tableCount('affiliate_link_batches'));

        // Misturado: só o produto com anúncio entra; nenhuma URL inventada para o outro.
        $batch = $provider->exportBatch($this->a->id, $this->ana, [$blocked, new ProductToLink('MLB100002', 'MLB7100002', 'Panela')]);
        self::assertSame('https://produto.mercadolivre.com.br/MLB-7100002', $batch->exportText());
        self::assertSame([['MLB100002', 'MLB7100002', 'https://produto.mercadolivre.com.br/MLB-7100002']], $this->rows('SELECT ml_product_id, offer_item_id, original_url FROM affiliate_link_batch_items'));
        self::assertSame(0, (int) $this->rows("SELECT COUNT(*) FROM affiliate_link_batch_items WHERE original_url LIKE '%/p/%'")[0][0]);
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
                VALUES (?, ?, ?, ?, ?, 'MLB456045', ?, ?, ?, 199.90, 249.90, 20, 'lowest_price')")
                ->execute([$installation->id->value, $run, $product, $sub[0], $sub[1], $i + 1, $i + 1, 'MLB7' . substr($product, 3)]);
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

    /** 31 produtos fictícios (MLB200001…31) numa seleção mais nova da conta A: o lote leva só 30. */
    private function thirtyOneCandidates(): void
    {
        $ids = [];
        foreach (range(1, 31) as $n) {
            $id = sprintf('MLB2000%02d', $n);
            $this->db->prepare("INSERT INTO ml_products (ml_product_id, site_id, status, name, domain_id, permalink, picture_url, pictures_count, fetched_at)
                VALUES (?, 'MLB', 'ok', ?, 'MLB-X', NULL, 'https://http2.mlstatic.com/x.jpg', 1, NOW(3))")->execute([$id, 'Produto lote ' . $n]);
            $ids[] = $id;
        }
        $this->candidates($this->a, $ids);
    }

    /** @return list<string> 30 links no formato do Gerador, na ordem do lote */
    private static function thirtyLinks(): array
    {
        return array_map(static fn (int $n): string => sprintf('https://meli.la/Lote30n%02d', $n), range(1, 30));
    }

    private function exportTextOf(string $batchKey): string
    {
        return implode("\n", array_column($this->rows('SELECT i.original_url FROM affiliate_link_batch_items i JOIN affiliate_link_batches b ON b.id = i.batch_id WHERE b.public_key = ? ORDER BY i.position', [$batchKey]), 0));
    }

    /** @return list<int> linhas com a caixa "incluir" marcada */
    private function checkedLines(string $html): array
    {
        preg_match_all('#name="incluir\[(\d+)\]"[^>]*\bchecked\b#', $html, $m);

        return array_map('intval', $m[1]);
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
