<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use DI\Container;
use Monolog\Handler\TestHandler;
use Psr\Log\LoggerInterface;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Application\Niche\NicheFilters;
use Sinergia\Application\Port\MercadoLivre\TokenSet;
use Sinergia\Application\Port\WhatsApp\ConnectionSnapshot;
use Sinergia\Application\Port\WhatsApp\ProviderInstance;
use Sinergia\Application\Queue\BotWorker;
use Sinergia\Application\Queue\QueueSender;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\AccountNicheRepository;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Infrastructure\Persistence\WhatsAppConnectionRepository;
use Sinergia\Kernel;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Clock\FrozenClock;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Tests\Support\FakeUazapiServer;
use Sinergia\Tests\Support\RoutedMercadoLivreHttp;

/**
 * Etapa 8: planner + sender + worker com o container real, MariaDB de teste, Mercado Livre FALSO e Uazapi FALSA.
 * Nenhuma mensagem real é enviada; tokens e links são fictícios.
 * Relógio congelado em 2026-09-28 15:00 UTC = 12:00 em São Paulo (dentro da janela 09:00–21:00).
 */
final class QueueWorkerTest extends DatabaseTestCase
{
    private const string NOW = '2026-09-28T15:00:00Z';

    private \PDO $db;
    private string $appKey;
    private FrozenClock $clock;
    private RoutedMercadoLivreHttp $ml;
    private FakeUazapiServer $uazapi;
    private TestHandler $logs;
    private Container $container;
    private Installation $a;
    private Installation $b;
    private int $ana;
    private int $bia;
    /** @var array<int, string> installation → token WhatsApp */
    private array $waToken = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->freshSchema();
        $installations = new InstallationRepository($this->db);
        $this->a = $installations->ensure('conta-a', 'Loja A', 'MLB');
        $this->b = $installations->ensure('conta-b', 'Loja B', 'MLB');
        $users = new UserRepository($this->db);
        $hash = (new PasswordHasher())->hash(new SensitiveValue('senha-qualquer-123'));
        $this->ana = $users->create($this->a->id, 'ana@loja-a.test', 'Ana', $hash);
        $this->bia = $users->create($this->b->id, 'bia@loja-b.test', 'Bia', $hash);
        $this->db->exec("UPDATE installations SET bot_status = 'active', destinations_revalidated_at = '2026-09-28 15:00:00.000'");

        $this->appKey = SecretBox::generateKeyBase64();
        $this->clock = new FrozenClock(self::NOW);
        $this->ml = new RoutedMercadoLivreHttp();
        $this->uazapi = new FakeUazapiServer();
        $this->logs = new TestHandler();
        $this->container = $this->build();

        foreach ([[$this->a, $this->ana, 'TESTE-ml-token-A'], [$this->b, $this->bia, 'TESTE-ml-token-B']] as [$inst, $user, $mlToken]) {
            $this->container->get(MlCredentialRepository::class)->save($inst->id, '1234567890123456', new TokenSet(new SensitiveValue($mlToken), new SensitiveValue($mlToken . '-r'), 21600, 'offline_access', 1, 'Bearer'), new \DateTimeImmutable(self::NOW));
            $this->waToken[$inst->id->value] = $this->uazapi->connectedInstance('sbm-' . $inst->slug);
            $wa = $this->container->get(WhatsAppConnectionRepository::class);
            $wa->ensure($inst->id, 'uazapi', 'sbm-' . $inst->slug, $user, new \DateTimeImmutable(self::NOW));
            $wa->storeInstance($inst->id, new ProviderInstance(null, 'sbm-' . $inst->slug, new SensitiveValue($this->waToken[$inst->id->value])), $user, new \DateTimeImmutable(self::NOW));
            $wa->recordSnapshot($inst->id, new ConnectionSnapshot(ConnectionSnapshot::CONNECTED), new \DateTimeImmutable(self::NOW));
        }
        foreach (['MLB200001' => 'Air Fryer 4L', 'MLB200002' => 'Air Fryer 6L', 'MLB200003' => 'Panela Pressão', 'MLB200004' => 'Jogo de Panelas', 'MLB200005' => 'Cafeteira'] as $id => $name) {
            $this->db->prepare("INSERT INTO ml_products (ml_product_id, site_id, status, name, domain_id, permalink, picture_url, pictures_count, catalog_status, fetched_at)
                VALUES (?, 'MLB', 'ok', ?, 'MLB-X', ?, ?, 1, 'active', NOW(3))")->execute([$id, $name, 'https://www.mercadolivre.com.br/p/' . $id, 'https://http2.mlstatic.com/D_' . $id . '.jpg']);
            $this->freshOffer($id, 19990, 24990);
        }
    }

    public function testAutoPlanningRespectsWindowCadenceRotationAndSendsWithExactLink(): void
    {
        $dest = $this->autoDestination($this->a, 'Ofertas Casa', ['air-fryers', 'panelas'], interval: 60);
        $this->candidates($this->a, [['MLB200001', 'air-fryers'], ['MLB200002', 'air-fryers'], ['MLB200003', 'panelas']]);
        foreach (['MLB200001' => 'https://meli.la/AAAA111', 'MLB200002' => 'https://meli.la/BBBB222', 'MLB200003' => 'https://meli.la/CCCC333'] as $p => $url) {
            $this->link($this->a, $p, $url);
        }

        $summary = $this->worker()->runOnce('w1', $this->clock->now());

        // Rodízio de subnichos: air-fryers, panelas, air-fryers; um a cada 60 min a partir de agora.
        self::assertSame([
            ['MLB200001', 'sent', '2026-09-28 15:00:00.000'],
            ['MLB200003', 'scheduled', '2026-09-28 16:00:00.000'],
            ['MLB200002', 'scheduled', '2026-09-28 17:00:00.000'],
        ], $this->rows('SELECT ml_product_id, status, scheduled_for FROM dispatch_queue WHERE destination_id = ? ORDER BY scheduled_for', [$dest]));
        self::assertSame(3, $summary[$this->a->id->value]['planned']);

        // Um único envio, pelo provedor, com o token da conta A e a mensagem fixa com o link exato.
        self::assertCount(1, $this->uazapi->sent);
        self::assertSame($this->waToken[$this->a->id->value], $this->uazapi->sent[0]['token']);
        $body = json_decode($this->uazapi->sent[0]['body'], true);
        self::assertSame('120363000000000101@g.us', $body['number']);
        self::assertSame('image', $body['type']);
        self::assertSame('https://http2.mlstatic.com/D_MLB200001.jpg', $body['file']);
        self::assertSame("Air Fryer 4L\n\nDe R$ 249,90 por R$ 199,90 (20% OFF)\n\nhttps://meli.la/AAAA111", $body['text']);
        self::assertSame([['1', '1']], $this->rows("SELECT use_count, COUNT(*) FROM affiliate_links WHERE affiliate_url = 'https://meli.la/AAAA111' GROUP BY use_count"));
        self::assertSame([['sent', '1', null]], $this->rows('SELECT outcome, attempt_no, error_code FROM dispatch_attempts'));

        // Mais ciclos no mesmo minuto: nada novo (cadência + não repetição).
        $this->worker()->runOnce('w1', $this->clock->now());
        $this->worker()->runOnce('w2', $this->clock->now());
        self::assertCount(1, $this->uazapi->sent);
        self::assertSame(3, $this->tableCount('dispatch_queue'));

        // 1 h depois sai o segundo; o produto nunca se repete no destino.
        $this->clock->advance('PT1H');
        $this->worker()->runOnce('w1', $this->clock->now());
        self::assertCount(2, $this->uazapi->sent);
        self::assertStringEndsWith('https://meli.la/CCCC333', json_decode($this->uazapi->sent[1]['body'], true)['text']);
        self::assertSame([['3', '3']], $this->rows('SELECT COUNT(*), COUNT(DISTINCT ml_product_id) FROM dispatch_queue'));
    }

    public function testCadenceIsEnforcedAtSendTimeAndWindowOutsideIsRescheduled(): void
    {
        $dest = $this->autoDestination($this->a, 'Ofertas', ['air-fryers'], interval: 60);
        $this->candidates($this->a, [['MLB200001', 'air-fryers'], ['MLB200002', 'air-fryers']]);
        $this->link($this->a, 'MLB200001', 'https://meli.la/AAAA111');
        $this->link($this->a, 'MLB200002', 'https://meli.la/BBBB222');
        $this->worker()->runOnce('w1', $this->clock->now());
        self::assertCount(1, $this->uazapi->sent);

        // Forçamos o 2º item para "agora": a cadência de 60 min segura e reagenda para 16:00 UTC.
        $this->db->exec("UPDATE dispatch_queue SET scheduled_for = '2026-09-28 15:00:00.000' WHERE status = 'scheduled'");
        $this->worker()->runOnce('w1', $this->clock->now());
        self::assertCount(1, $this->uazapi->sent);
        self::assertSame([['scheduled', '2026-09-28 16:00:00.000', 'cadence']], $this->rows("SELECT status, scheduled_for, last_error FROM dispatch_queue WHERE status = 'scheduled'"));

        // Fora da janela (22:30 local): não envia e vai para 09:00 do dia seguinte.
        $this->clock = new FrozenClock('2026-09-29T01:30:00Z');
        $this->container = $this->build();
        $this->worker()->runOnce('w1', $this->clock->now());
        self::assertCount(1, $this->uazapi->sent);
        self::assertSame([['2026-09-29 12:00:00.000', 'outside_window']], $this->rows("SELECT scheduled_for, last_error FROM dispatch_queue WHERE status = 'scheduled'"));
        self::assertNotNull($dest);
    }

    public function testManualModeRequiresApprovalAndSkipIsFinal(): void
    {
        $dest = $this->autoDestination($this->a, 'Manual', ['air-fryers'], interval: 30, mode: 'manual');
        $this->candidates($this->a, [['MLB200001', 'air-fryers'], ['MLB200002', 'air-fryers']]);
        $this->link($this->a, 'MLB200001', 'https://meli.la/AAAA111');
        $this->link($this->a, 'MLB200002', 'https://meli.la/BBBB222');
        $this->worker()->runOnce('w1', $this->clock->now());

        self::assertSame(['pending_approval', 'pending_approval'], array_column($this->rows('SELECT status FROM dispatch_queue ORDER BY scheduled_for'), 0));
        self::assertSame([], $this->uazapi->sent, 'Modo manual nunca envia sem aprovação.');

        $keys = array_column($this->rows('SELECT public_key FROM dispatch_queue ORDER BY scheduled_for'), 0);
        self::assertTrue($this->decisions()->approve($this->a->id, (string) $keys[0], $this->ana, $this->clock->now()));
        self::assertTrue($this->decisions()->skip($this->a->id, (string) $keys[1], $this->ana, $this->clock->now()));
        self::assertFalse($this->decisions()->approve($this->a->id, (string) $keys[1], $this->ana, $this->clock->now()), 'Pulado não volta.');
        $this->worker()->runOnce('w1', $this->clock->now());

        self::assertCount(1, $this->uazapi->sent);
        self::assertSame([['sent', (string) $this->ana], ['skipped', (string) $this->ana]], $this->rows('SELECT status, decided_by_user_id FROM dispatch_queue ORDER BY scheduled_for'));
        // O produto pulado não é planejado de novo neste destino.
        $this->clock->advance('PT2H');
        $this->worker()->runOnce('w1', $this->clock->now());
        self::assertSame(2, $this->tableCount('dispatch_queue'));
        self::assertNotNull($dest);
    }

    public function testAwaitingAffiliateLinkNeverFallsBackToPlainUrlAndIsPromotedWhenLinked(): void
    {
        $this->autoDestination($this->a, 'Ofertas', ['air-fryers'], interval: 60);
        $this->candidates($this->a, [['MLB200001', 'air-fryers']]);
        $this->worker()->runOnce('w1', $this->clock->now());

        self::assertSame([['awaiting_affiliate_link', null]], $this->rows('SELECT status, affiliate_link_id FROM dispatch_queue'));
        self::assertSame([], $this->uazapi->sent);

        // Link de OUTRA conta para o mesmo produto não serve.
        $this->link($this->b, 'MLB200001', 'https://meli.la/ContaBBB');
        $this->worker()->runOnce('w1', $this->clock->now());
        self::assertSame([['awaiting_affiliate_link']], $this->rows('SELECT status FROM dispatch_queue WHERE installation_id = ' . $this->a->id->value));
        self::assertSame([], $this->uazapi->sent);

        $this->link($this->a, 'MLB200001', 'https://meli.la/ContaAAA');
        $this->worker()->runOnce('w1', $this->clock->now());
        self::assertCount(1, $this->uazapi->sent);
        self::assertStringEndsWith("\nhttps://meli.la/ContaAAA", json_decode($this->uazapi->sent[0]['body'], true)['text']);
        self::assertStringNotContainsString('mercadolivre.com.br', json_decode($this->uazapi->sent[0]['body'], true)['text']);
    }

    public function testRevalidationBeforeSendUsesReplacedLinkAndCurrentPrice(): void
    {
        $this->autoDestination($this->a, 'Ofertas', ['air-fryers'], interval: 60);
        $this->candidates($this->a, [['MLB200001', 'air-fryers']]);
        $old = $this->link($this->a, 'MLB200001', 'https://meli.la/Antigo1');
        $this->planner()->plan($this->a->id);
        self::assertSame([['scheduled', (string) $old, '199.90', '249.90']], $this->rows('SELECT status, affiliate_link_id, planned_price, planned_original FROM dispatch_queue'));

        // Antes do envio: link substituído e preço mudou (agora sem preço anterior).
        $this->db->prepare("UPDATE affiliate_links SET status = 'replaced', replaced_at = NOW(3) WHERE id = ?")->execute([$old]);
        $new = $this->link($this->a, 'MLB200001', 'https://meli.la/Novo222');
        $this->freshOffer('MLB200001', 17990, null);
        $this->worker()->runOnce('w1', $this->clock->now());

        self::assertCount(1, $this->uazapi->sent);
        self::assertSame("Air Fryer 4L\n\nPor R$ 179,90\n\nhttps://meli.la/Novo222", json_decode($this->uazapi->sent[0]['body'], true)['text']);
        $row = $this->rows('SELECT affiliate_link_id, planned_price, planned_original, message_json FROM dispatch_queue')[0];
        self::assertSame([(string) $new, '179.90', null], [$row[0], $row[1], $row[2]]);
        self::assertSame(17990, json_decode((string) $row[3], true)['price']);
        self::assertNull(json_decode((string) $row[3], true)['original_price']);
        self::assertSame([['0'], ['1']], $this->rows('SELECT use_count FROM affiliate_links ORDER BY id'), 'O uso conta no link realmente enviado.');
    }

    public function testPauseDestinationIneligibilityAndFiltersStopSending(): void
    {
        $dest = $this->autoDestination($this->a, 'Ofertas', ['air-fryers'], interval: 30);
        $this->candidates($this->a, [['MLB200001', 'air-fryers'], ['MLB200002', 'air-fryers'], ['MLB200003', 'air-fryers']]);
        foreach (['MLB200001', 'MLB200002', 'MLB200003'] as $i => $p) {
            $this->link($this->a, $p, 'https://meli.la/Link00' . $i);
        }

        // Bot global pausado: nada é planejado nem enviado; nada é apagado.
        $this->db->exec("UPDATE installations SET bot_status = 'paused' WHERE id = " . $this->a->id->value);
        self::assertArrayNotHasKey($this->a->id->value, $this->worker()->runOnce('w1', $this->clock->now()), 'Conta pausada nem entra no ciclo.');
        self::assertSame(0, $this->tableCount('dispatch_queue'));
        $this->db->exec("UPDATE installations SET bot_status = 'active' WHERE id = " . $this->a->id->value);

        // Destino pausado: nada sai; a fila continua.
        $this->db->exec("UPDATE destinations SET status = 'paused', user_paused = 1 WHERE id = $dest");
        $this->worker()->runOnce('w1', $this->clock->now());
        self::assertSame([], $this->uazapi->sent);
        $this->db->exec("UPDATE destinations SET status = 'active', user_paused = 0 WHERE id = $dest");
        $this->worker()->runOnce('w1', $this->clock->now());
        self::assertCount(1, $this->uazapi->sent);

        // Revalidação periódica: o grupo passou a exigir aprovação → inelegível → para de receber na hora.
        $this->uazapi->groups[$this->waToken[$this->a->id->value]] = [FakeUazapiServer::group('120363000000000101@g.us', 'Ofertas', ['IsJoinApprovalRequired' => true])];
        $this->db->exec("UPDATE installations SET destinations_revalidated_at = '2026-09-28 08:00:00.000' WHERE id = " . $this->a->id->value);
        $this->clock->advance('PT1H');
        $this->worker()->runOnce('w1', $this->clock->now());
        self::assertSame([['ineligible', 'join_approval_required']], $this->rows("SELECT status, ineligible_reason FROM destinations WHERE id = $dest"));
        self::assertCount(1, $this->uazapi->sent, 'Destino inelegível não recebe.');
        self::assertSame(2, (int) $this->db->query("SELECT COUNT(*) FROM dispatch_queue WHERE status = 'scheduled'")->fetchColumn(), 'A fila é mantida.');
    }

    public function testProductRevalidationSkipsWhenFiltersOrAvailabilityChange(): void
    {
        $this->autoDestination($this->a, 'Ofertas', ['air-fryers'], interval: 10);
        (new AccountNicheRepository($this->db))->save($this->a->id, $this->nicheId('casa-cozinha'), [$this->subId('air-fryers')], new NicheFilters(15, null, null, true), $this->ana, new \DateTimeImmutable(self::NOW));
        $this->candidates($this->a, [['MLB200001', 'air-fryers'], ['MLB200002', 'air-fryers']]);
        $this->link($this->a, 'MLB200001', 'https://meli.la/AAAA111');
        $this->link($this->a, 'MLB200002', 'https://meli.la/BBBB222');
        // Hoje o 1º só tem 4% de desconto (< 15%) e o 2º sumiu do catálogo.
        $this->freshOffer('MLB200001', 19990, 20900);
        $this->ml->on('/products/MLB200002', 404, ['message' => 'not found']);
        $this->worker()->runOnce('w1', $this->clock->now());
        $this->clock->advance('PT10M');
        $this->worker()->runOnce('w1', $this->clock->now());

        self::assertSame([], $this->uazapi->sent);
        self::assertSame([['skipped', 'filters_no_longer_match'], ['skipped', 'product_unavailable']], $this->rows('SELECT status, last_error FROM dispatch_queue ORDER BY scheduled_for'));
    }

    public function testProviderFailurePolicy(): void
    {
        $cases = [
            [401, 'scheduled', 'unauthorized', 'retry', 30],
            [403, 'scheduled', 'forbidden', 'retry', 30],
            [429, 'scheduled', 'rate_limited', 'retry', 5],
            [503, 'scheduled', 'server_error', 'retry', 5],
            [500, 'failed', 'unknown_outcome_server_error', 'unknown', null],
            [502, 'failed', 'unknown_outcome_server_error', 'unknown', null],
            ['timeout', 'failed', 'unknown_outcome_timeout', 'unknown', null],
            ['invalid', 'failed', 'unknown_outcome_invalid_response', 'unknown', null],
            [404, 'failed', 'destination_not_found', 'failed', null],
        ];
        foreach ($cases as $i => [$failure, $status, $error, $outcome, $retryMinutes]) {
            $this->setUp();
            $dest = $this->autoDestination($this->a, 'Ofertas', ['air-fryers'], interval: 60);
            $this->candidates($this->a, [['MLB200001', 'air-fryers']]);
            $this->link($this->a, 'MLB200001', 'https://meli.la/AAAA111');
            $this->uazapi->fail('/send/media', $failure);
            $this->worker()->runOnce('w1', $this->clock->now());

            $row = $this->rows('SELECT status, last_error, attempts, next_attempt_at, send_started_at FROM dispatch_queue')[0];
            self::assertSame([$status, $error, '1'], [$row[0], $row[1], $row[2]], (string) $failure);
            self::assertSame([[$outcome]], $this->rows('SELECT outcome FROM dispatch_attempts'), (string) $failure);
            if ($retryMinutes !== null) {
                self::assertSame((new \DateTimeImmutable(self::NOW))->modify("+$retryMinutes minutes")->format('Y-m-d H:i:s.000'), $row[3]);
                self::assertNull($row[4], 'Não enviado: envio iniciado é limpo para a próxima tentativa.');
            }
            if ($failure === 404) {
                self::assertSame([['ineligible', 'not_found_in_whatsapp']], $this->rows("SELECT status, ineligible_reason FROM destinations WHERE id = $dest"));
            }
            // Falha com resultado incerto nunca é reenviada, nem horas depois.
            $this->clock->advance('PT3H');
            $this->worker()->runOnce('w1', $this->clock->now());
            $sends = $this->uazapi->count('POST /send/media');
            self::assertSame($retryMinutes === null ? 1 : 2, $sends, (string) $failure);
        }
    }

    public function testRetryIsLimitedToThreeAttempts(): void
    {
        $this->autoDestination($this->a, 'Ofertas', ['air-fryers'], interval: 60);
        $this->candidates($this->a, [['MLB200001', 'air-fryers']]);
        $this->link($this->a, 'MLB200001', 'https://meli.la/AAAA111');
        $this->uazapi->fail('/send/media', 429, 429, 429, 429);
        for ($i = 0; $i < 6; $i++) {
            $this->worker()->runOnce('w1', $this->clock->now());
            $this->clock->advance('PT1H');
        }

        self::assertSame(3, $this->uazapi->count('POST /send/media'));
        self::assertSame([['failed', 'rate_limited_max_attempts', '3']], $this->rows('SELECT status, last_error, attempts FROM dispatch_queue'));
        self::assertSame(['retry', 'retry', 'failed'], array_column($this->rows('SELECT outcome FROM dispatch_attempts ORDER BY id'), 0));
    }

    public function testTwoConcurrentWorkersNeverSendTheSameItem(): void
    {
        $this->autoDestination($this->a, 'Ofertas', ['air-fryers'], interval: 60);
        $this->candidates($this->a, [['MLB200001', 'air-fryers']]);
        $this->link($this->a, 'MLB200001', 'https://meli.la/AAAA111');
        $this->worker()->runOnce('w0', $this->clock->now());   // planeja e envia o 1º
        $this->uazapi->sent = [];
        $this->uazapi->requests = [];
        $this->db->exec("UPDATE dispatch_queue SET status = 'scheduled', sent_at = NULL, message_json = NULL");
        $this->db->exec('UPDATE destinations SET last_sent_at = NULL');

        $other = $this->build();   // outro processo: outra conexão ao banco
        $inner = [];
        $this->uazapi->before['/send/media'] = function () use ($other, &$inner): void {
            unset($this->uazapi->before['/send/media']);
            // Enquanto o worker 1 está no meio do envio, o worker 2 roda: trava da conta ocupada…
            $inner['worker'] = $other->get(BotWorker::class)->runOnce('w2', $this->clock->now());
            // …e mesmo sem a trava, o item já está reservado: não há o que pegar.
            $inner['sender'] = $other->get(QueueSender::class)->sendDue($this->a->id, 'w3', 5);
        };
        $this->worker()->runOnce('w1', $this->clock->now());

        self::assertSame('busy', $inner['worker'][$this->a->id->value]);
        self::assertSame([], $inner['sender']);
        self::assertSame(1, $this->uazapi->count('POST /send/media'));
        self::assertSame([['sent']], $this->rows('SELECT status FROM dispatch_queue'));
    }

    public function testClaimIsAtomicEvenWhenBothWorkersSawTheSameDueItem(): void
    {
        $this->autoDestination($this->a, 'Ofertas', ['air-fryers'], interval: 60);
        $this->candidates($this->a, [['MLB200001', 'air-fryers']]);
        $this->link($this->a, 'MLB200001', 'https://meli.la/AAAA111');
        $this->planner()->plan($this->a->id);

        // Corrida real: os dois workers leem a lista de vencidos ANTES de qualquer reserva.
        $w1 = $this->decisions();
        $w2 = $this->build()->get(\Sinergia\Infrastructure\Persistence\DispatchQueueRepository::class);
        $seen1 = $w1->dueItemIds($this->a->id, $this->clock->now(), 5);
        $seen2 = $w2->dueItemIds($this->a->id, $this->clock->now(), 5);
        self::assertSame($seen1, $seen2);

        self::assertTrue($w1->claim($this->a->id, $seen1[0], str_repeat('1', 32), $this->clock->now()));
        self::assertFalse($w2->claim($this->a->id, $seen2[0], str_repeat('2', 32), $this->clock->now()), 'Só um worker reserva o item.');
        self::assertNull($w2->loadClaimed($this->a->id, $seen2[0], str_repeat('2', 32)));
        self::assertSame([['sending', str_repeat('1', 32)]], $this->rows('SELECT status, claim_token FROM dispatch_queue'));
    }

    public function testInterruptedWorkerNeverCausesDuplicateSend(): void
    {
        $this->autoDestination($this->a, 'Ofertas', ['air-fryers'], interval: 30);
        $this->candidates($this->a, [['MLB200001', 'air-fryers'], ['MLB200002', 'air-fryers']]);
        $this->link($this->a, 'MLB200001', 'https://meli.la/AAAA111');
        $this->link($this->a, 'MLB200002', 'https://meli.la/BBBB222');
        $this->planner()->plan($this->a->id);
        [$first, $second] = array_map('intval', array_column($this->rows('SELECT id FROM dispatch_queue ORDER BY scheduled_for'), 0));

        // Worker morreu: o 1º já tinha começado o envio (resultado incerto); o 2º só estava reservado.
        $this->db->exec("UPDATE dispatch_queue SET status = 'sending', claim_token = REPEAT('a', 32), claimed_at = '2026-09-28 14:40:00.000', send_started_at = '2026-09-28 14:40:01.000', attempts = 1, message_json = '{}' WHERE id = $first");
        $this->db->exec("INSERT INTO dispatch_attempts (installation_id, queue_id, attempt_no, worker_id, started_at) VALUES ({$this->a->id->value}, $first, 1, 'morto', '2026-09-28 14:40:01.000')");
        $this->db->exec("UPDATE dispatch_queue SET status = 'sending', claim_token = REPEAT('b', 32), claimed_at = '2026-09-28 14:40:00.000', scheduled_for = '2026-09-28 15:00:00.000' WHERE id = $second");

        $summary = $this->worker()->runOnce('w1', $this->clock->now());

        self::assertSame(['requeued' => 1, 'unknown' => 1], $summary[$this->a->id->value]['recovered']);
        self::assertSame([['failed', 'interrupted_unknown_outcome'], ['sent', null]], $this->rows('SELECT status, last_error FROM dispatch_queue ORDER BY id'));
        self::assertSame(1, $this->uazapi->count('POST /send/media'), 'O incerto nunca é reenviado; o só-reservado sai uma vez.');
        self::assertSame([['unknown', 'interrupted']], $this->rows("SELECT outcome, error_code FROM dispatch_attempts WHERE worker_id = 'morto'"));
    }

    public function testAccountsAreIsolatedEndToEnd(): void
    {
        $destA = $this->autoDestination($this->a, 'Ofertas A', ['air-fryers'], interval: 60);
        $destB = $this->autoDestination($this->b, 'Ofertas B', ['air-fryers'], interval: 60, jid: '120363000000000202@g.us');
        $this->candidates($this->a, [['MLB200001', 'air-fryers']]);
        $this->candidates($this->b, [['MLB200001', 'air-fryers'], ['MLB200002', 'air-fryers']]);
        $this->link($this->a, 'MLB200001', 'https://meli.la/LinkDaA');
        $this->link($this->b, 'MLB200001', 'https://meli.la/LinkDaB');
        // MLB200002 só tem link em A → para B fica aguardando.
        $this->link($this->a, 'MLB200002', 'https://meli.la/SoDaA02');

        $this->worker()->runOnce('w1', $this->clock->now());

        $byToken = [];
        foreach ($this->uazapi->sent as $sent) {
            $body = json_decode($sent['body'], true);
            $byToken[$sent['token']][] = [$body['number'], substr($body['text'], strrpos($body['text'], "\n") + 1)];
        }
        self::assertSame([
            $this->waToken[$this->a->id->value] => [['120363000000000101@g.us', 'https://meli.la/LinkDaA']],
            $this->waToken[$this->b->id->value] => [['120363000000000202@g.us', 'https://meli.la/LinkDaB']],
        ], $byToken);
        self::assertSame([['awaiting_affiliate_link']], $this->rows("SELECT status FROM dispatch_queue WHERE installation_id = ? AND ml_product_id = 'MLB200002'", [$this->b->id->value]));
        // A fila de A só tem candidatos da seleção de A (MLB200002 é candidato só de B).
        self::assertSame([['MLB200001']], $this->rows('SELECT ml_product_id FROM dispatch_queue WHERE installation_id = ?', [$this->a->id->value]));
        // Cada consulta ao ML usou o token da própria conta.
        self::assertEqualsCanonicalizing(['TESTE-ml-token-A', 'TESTE-ml-token-B'], array_values(array_unique(array_column($this->ml->requests, 'token'))));
        // Uma conta não decide itens da outra.
        $keyB = (string) $this->rows('SELECT public_key FROM dispatch_queue WHERE installation_id = ? LIMIT 1', [$this->b->id->value])[0][0];
        self::assertFalse($this->decisions()->skip($this->a->id, $keyB, $this->ana, $this->clock->now()));
        self::assertNotNull($destA);
        self::assertNotNull($destB);
    }

    private function build(): Container
    {
        $env = array_filter(array_merge($_ENV, getenv()), 'is_string');
        $container = Kernel::container(Config::fromArray([
            'APP_ENV' => 'test',
            'APP_KEY' => $this->appKey,
            'DB_HOST' => (string) ($env['TEST_DB_HOST'] ?? ''),
            'DB_PORT' => (string) ($env['TEST_DB_PORT'] ?? '3306'),
            'DB_DATABASE' => (string) ($env['TEST_DB_DATABASE'] ?? ''),
            'DB_USERNAME' => (string) ($env['TEST_DB_USERNAME'] ?? ''),
            'DB_PASSWORD' => (string) ($env['TEST_DB_PASSWORD'] ?? ''),
            'ML_CLIENT_ID' => '1234567890123456',
            'ML_CLIENT_SECRET' => 'segredo-ficticio',
            'ML_REDIRECT_URI' => 'https://app.example.test/oauth/mercadolivre/callback',
            'UAZAPI_BASE_URL' => FakeUazapiServer::BASE_URL,
            'UAZAPI_ADMIN_TOKEN' => FakeUazapiServer::ADMIN_TOKEN,
        ]), dirname(__DIR__, 2));
        self::assertInstanceOf(Container::class, $container);
        $container->set(Clock::class, $this->clock);
        $container->set('ml.http', $this->ml->client());
        $container->set('whatsapp.http', $this->uazapi->client());
        $container->set(LoggerInterface::class, LoggerFactory::create('local', $this->logs));

        return $container;
    }

    private function planner(): \Sinergia\Application\Queue\QueuePlanner
    {
        return $this->container->get(\Sinergia\Application\Queue\QueuePlanner::class);
    }

    private function worker(): BotWorker
    {
        return $this->container->get(BotWorker::class);
    }

    private function decisions(): \Sinergia\Infrastructure\Persistence\DispatchQueueRepository
    {
        return $this->container->get(\Sinergia\Infrastructure\Persistence\DispatchQueueRepository::class);
    }

    /** @param list<string> $subSlugs */
    private function autoDestination(Installation $inst, string $name, array $subSlugs, int $interval, string $mode = 'auto', string $jid = '120363000000000101@g.us'): int
    {
        $niche = $this->nicheId('casa-cozinha');
        $subs = array_map(fn (string $s): int => $this->subId($s), $subSlugs);
        $user = $inst->id->equals($this->a->id) ? $this->ana : $this->bia;
        (new AccountNicheRepository($this->db))->save($inst->id, $niche, $subs, NicheFilters::defaults(), $user, new \DateTimeImmutable(self::NOW));
        $this->db->prepare("INSERT INTO destinations (installation_id, public_key, type, provider_ref, name, niche_id, mode, window_start, window_end, interval_minutes,
                user_paused, status, eligibility, tech_checked_at, tech_present, tech_is_channel, tech_we_are_admin, tech_has_invite_link, tech_join_approval,
                tech_announce_only, tech_is_community, public_declared, public_declared_at, public_declared_by_user_id, public_declaration_version,
                media_registered_declared, media_declared_at, media_declared_by_user_id, media_declaration_version, created_at, created_by_user_id, updated_at)
            VALUES (?, ?, 'group', ?, ?, ?, ?, '09:00:00', '21:00:00', ?, 0, 'active', 'group_declared_public', NOW(3), 1, 0, 1, 1, 0, 1, 0,
                1, NOW(3), ?, 'v1', 1, NOW(3), ?, 'v1', NOW(3), ?, NOW(3))")
            ->execute([$inst->id->value, bin2hex(random_bytes(10)), $jid, $name, $niche, $mode, $interval, $user, $user, $user]);
        $id = (int) $this->db->lastInsertId();
        foreach ($subs as $sub) {
            $this->db->prepare('INSERT INTO destination_subniches (installation_id, destination_id, niche_id, subniche_id) VALUES (?, ?, ?, ?)')->execute([$inst->id->value, $id, $niche, $sub]);
        }
        $this->uazapi->groups[$this->waToken[$inst->id->value]][] = FakeUazapiServer::group($jid, $name);

        return $id;
    }

    /** @param list<array{0: string, 1: string}> $items */
    private function candidates(Installation $inst, array $items): void
    {
        $this->db->prepare("INSERT INTO offer_selection_runs (installation_id, status, started_at, finished_at) VALUES (?, 'completed', ?, ?)")
            ->execute([$inst->id->value, '2026-09-28 15:00:00.000', '2026-09-28 15:00:00.000']);
        $run = (int) $this->db->lastInsertId();
        foreach ($items as $i => [$product, $sub]) {
            $this->db->prepare("INSERT INTO account_offer_candidates (installation_id, run_id, ml_product_id, niche_id, subniche_id, ml_category_id, ranking_position, sort_order, item_id, price, original_price, discount_pct, selection_rule)
                VALUES (?, ?, ?, ?, ?, 'MLB456045', ?, ?, 'MLB7000000001', 199.90, 249.90, 20, 'buy_box')")
                ->execute([$inst->id->value, $run, $product, $this->nicheId('casa-cozinha'), $this->subId($sub), $i + 1, $i + 1]);
        }
    }

    private function link(Installation $inst, string $product, string $url): int
    {
        $user = $inst->id->equals($this->a->id) ? $this->ana : $this->bia;
        $this->db->prepare("INSERT INTO affiliate_links (installation_id, site_id, ml_product_id, original_url, affiliate_url, affiliate_url_sha256, source, status,
                received_at, confirmed_at, confirmed_by_user_id, created_at, updated_at)
            VALUES (?, 'MLB', ?, ?, ?, SHA2(?, 256), 'manual_batch', 'active', NOW(3), NOW(3), ?, NOW(3), NOW(3))")
            ->execute([$inst->id->value, $product, 'https://www.mercadolivre.com.br/p/' . $product, $url, $url, $user]);

        return (int) $this->db->lastInsertId();
    }

    private function freshOffer(string $product, int $priceCents, ?int $originalCents): void
    {
        $name = (string) ($this->rows('SELECT name FROM ml_products WHERE ml_product_id = ?', [$product])[0][0] ?? $product);
        $this->ml->product($product, 'MLB-X', [
            'name' => $name,
            'pictures' => [['id' => 'x', 'url' => 'https://http2.mlstatic.com/D_' . $product . '.jpg']],
            'permalink' => 'https://www.mercadolivre.com.br/p/' . $product,
            'buy_box_winner' => ['item_id' => 'MLB7000000009', 'price' => $priceCents / 100, 'original_price' => $originalCents === null ? null : $originalCents / 100, 'currency_id' => 'BRL', 'condition' => 'new'],
        ]);
    }

    private function nicheId(string $slug): int
    {
        return (int) $this->rows('SELECT id FROM niches WHERE slug = ?', [$slug])[0][0];
    }

    private function subId(string $slug): int
    {
        return (int) $this->rows('SELECT id FROM subniches WHERE slug = ?', [$slug])[0][0];
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
