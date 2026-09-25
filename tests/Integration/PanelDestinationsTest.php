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
use Sinergia\Application\Port\WhatsApp\ConnectionSnapshot;
use Sinergia\Application\Port\WhatsApp\ProviderInstance;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\AccountNicheRepository;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Infrastructure\Persistence\WhatsAppConnectionRepository;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Tests\Support\FakeUazapiServer;
use Sinergia\Tests\Support\PanelRequests;
use Sinergia\Web\HttpApp;

/**
 * Etapa 6: Destinos com container real, MariaDB de teste e Uazapi FALSA (nenhum envio real).
 */
final class PanelDestinationsTest extends DatabaseTestCase
{
    use PanelRequests;

    private const string PASSWORD = 'senha-do-painel-123';
    private const string G_OPEN = '120363000000000001@g.us';
    private const string G_APPROVAL = '120363000000000002@g.us';
    private const string G_UNKNOWN = '120363000000000003@g.us';
    private const string G_COMMUNITY = '120363000000000004@g.us';
    private const string G_NOT_ADMIN = '120363000000000005@g.us';
    private const string C_OWNER = '120363111111111111@newsletter';
    private const string C_FOLLOWER = '120363111111111112@newsletter';
    private const string C_UNKNOWN = '120363111111111113@newsletter';
    private const string G_B = '120363999999999991@g.us';

    private \PDO $db;
    private string $appKey;
    private Installation $a;
    private Installation $b;
    private int $ana;
    private int $bia;
    private FakeUazapiServer $uazapi;
    private string $tokenA;
    private string $tokenB;
    private App $app;
    private ?TestHandler $logs = null;

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
        $this->appKey = SecretBox::generateKeyBase64();

        $this->uazapi = new FakeUazapiServer();
        $this->tokenA = $this->uazapi->connectedInstance('sbm-a');
        $this->tokenB = $this->uazapi->connectedInstance('sbm-b');
        $this->uazapi->groups[$this->tokenA] = [
            FakeUazapiServer::group(self::G_OPEN, 'Ofertas Casa'),
            FakeUazapiServer::group(self::G_APPROVAL, 'Família', ['IsJoinApprovalRequired' => true]),
            // Só JID e nome: nada informado sobre admin, aprovação ou link.
            ['JID' => self::G_UNKNOWN, 'Name' => 'Sem informação', '_invite' => false],
            FakeUazapiServer::group(self::G_COMMUNITY, 'Comunidade Bairro', ['IsParent' => true]),
            FakeUazapiServer::group(self::G_NOT_ADMIN, 'Grupo dos Amigos', ['OwnerIsAdmin' => false, '_invite' => false]),
        ];
        $this->uazapi->channels[$this->tokenA] = [
            FakeUazapiServer::channel(self::C_OWNER, 'Canal Ofertas A'),
            FakeUazapiServer::channel(self::C_FOLLOWER, 'Canal que sigo', 'subscriber'),
            FakeUazapiServer::channel(self::C_UNKNOWN, 'Canal sem papel', null),
        ];
        $this->uazapi->groups[$this->tokenB] = [FakeUazapiServer::group(self::G_B, 'Grupo da B')];

        $this->app = $this->build([]);
        $this->connect($this->a, $this->tokenA, $this->ana);
        $this->connect($this->b, $this->tokenB, $this->bia);
        $this->chooseNiches($this->a, $this->ana, 'casa-cozinha', ['air-fryers', 'panelas']);
        $this->chooseNiches($this->a, $this->ana, 'beleza-cuidados', ['perfumes']);
        $this->chooseNiches($this->b, $this->bia, 'casa-cozinha', ['cafeteiras']);
    }

    protected function panelApp(): App
    {
        return $this->app;
    }

    public function testSyncListsOnlyOwnWhatsAppWithoutTechnicalIds(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        self::assertStringContainsString('Nada para adicionar', $this->page($ana));

        self::assertSame('/destinos?ok=sincronizado', $this->post($ana, '/destinos/sincronizar')->getHeaderLine('Location'));
        $page = $this->page($ana);
        foreach (['Ofertas Casa', 'Família', 'Sem informação', 'Comunidade Bairro', 'Grupo dos Amigos', 'Canal Ofertas A', 'Canal que sigo'] as $name) {
            self::assertStringContainsString($name, $page);
        }
        self::assertStringNotContainsString('Grupo da B', $page);
        self::assertStringContainsString('Exige aprovação para entrar', $page);
        $this->assertNothingTechnicalLeaked($page);
        self::assertSame([$this->tokenA], $this->uazapi->tokensUsed(), 'A sincronização usa só o token da própria conta.');
        self::assertSame(['POST /group/list', 'GET /newsletter/list'], $this->uazapi->paths());
    }

    public function testGroupDeclaredPublicFlowRecordsWhoWhenAndVersion(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->post($ana, '/destinos/sincronizar');
        self::assertSame('/destinos?ok=adicionado', $this->add($ana, 'Ofertas Casa')->getHeaderLine('Location'));
        self::assertContains('POST /group/info', $this->uazapi->paths(), 'Ao adicionar, o grupo é reconsultado com o token da conta.');
        $key = $this->keyOf($this->a, self::G_OPEN);

        self::assertStringContainsString('Não elegível — falta a declaração de grupo público', $this->page($ana));
        $fact = $this->dbRow($this->a, self::G_OPEN);
        self::assertSame([1, 1, 0, 1, 0, null], [$fact['tech_we_are_admin'], $fact['tech_has_invite_link'], $fact['tech_join_approval'], $fact['tech_announce_only'], $fact['tech_is_community'], $fact['tech_blocking_reason']]);

        // Declaração sem marcar a caixa não é registrada.
        self::assertSame('/destinos?erro=confirmation_required', $this->post($ana, "/destinos/$key/declarar", ['tipo' => 'public_group', 'valor' => '1'])->getHeaderLine('Location'));
        $this->declare($ana, $key, 'public_group');
        self::assertStringContainsString('Não elegível — falta a declaração de Mídia cadastrada', $this->page($ana));
        $this->declare($ana, $key, 'media_registered');
        $page = $this->page($ana);
        self::assertStringContainsString('Grupo declarado público', $page);
        self::assertStringNotContainsString('comprovado', $page);
        self::assertStringContainsString('Declarado</strong> por Ana', $page);

        $row = $this->dbRow($this->a, self::G_OPEN);
        self::assertSame(['group_declared_public', 1, $this->ana, 'v1', 1, $this->ana, 'v1'], [
            $row['eligibility'], $row['public_declared'], $row['public_declared_by_user_id'], $row['public_declaration_version'],
            $row['media_registered_declared'], $row['media_declared_by_user_id'], $row['media_declaration_version'],
        ]);
        self::assertNotNull($row['public_declared_at']);

        // Retirar a declaração volta a "Não elegível" (e derruba um destino ativo).
        $this->configure($ana, $key, []);
        $this->post($ana, "/destinos/$key/ativar");
        self::assertSame('active', $this->dbRow($this->a, self::G_OPEN)['status']);
        $this->post($ana, "/destinos/$key/declarar", ['tipo' => 'public_group', 'valor' => '0']);
        self::assertSame(['ineligible', 'ineligible', 'public_declaration_missing'], array_values(array_intersect_key($this->dbRow($this->a, self::G_OPEN), array_flip(['status', 'eligibility', 'ineligible_reason']))));
    }

    public function testTechnicalBlocksWinOverDeclarations(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->post($ana, '/destinos/sincronizar');
        $expected = [
            self::G_APPROVAL => ['Família', 'join_approval_required', 'exige aprovação para entrar'],
            self::G_UNKNOWN => ['Sem informação', 'insufficient_information', 'o WhatsApp não informou dados suficientes para verificar'],
            self::G_COMMUNITY => ['Comunidade Bairro', 'community_group', 'é uma comunidade ou subgrupo de comunidade'],
            self::G_NOT_ADMIN => ['Grupo dos Amigos', 'not_admin', 'seu número não é administrador do grupo'],
        ];
        foreach ($expected as $jid => [$name, $reason, $text]) {
            $this->add($ana, $name);
            $key = $this->keyOf($this->a, $jid);
            $this->declare($ana, $key, 'public_group');
            $this->declare($ana, $key, 'media_registered');
            $this->configure($ana, $key, []);

            $row = $this->dbRow($this->a, $jid);
            self::assertSame(['ineligible', $reason, $reason], [$row['eligibility'], $row['ineligible_reason'], $row['tech_blocking_reason']], $name);
            self::assertStringContainsString('Não elegível — ' . $text, $this->page($ana));
            self::assertSame('/destinos?erro=not_eligible', $this->post($ana, "/destinos/$key/ativar")->getHeaderLine('Location'));
            self::assertSame('ineligible', $this->dbRow($this->a, $jid)['status']);
        }
        // Sem informação: nada foi convertido em "sim".
        $unknown = $this->dbRow($this->a, self::G_UNKNOWN);
        self::assertSame([null, null, null], [$unknown['tech_we_are_admin'], $unknown['tech_has_invite_link'], $unknown['tech_join_approval']]);
    }

    public function testChannels(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->post($ana, '/destinos/sincronizar');
        $this->add($ana, 'Canal Ofertas A');
        $key = $this->keyOf($this->a, self::C_OWNER);
        self::assertStringContainsString('Não elegível — falta a declaração de Mídia cadastrada', $this->page($ana));
        self::assertSame('/destinos?erro=not_a_group', $this->post($ana, "/destinos/$key/declarar", ['tipo' => 'public_group', 'valor' => '1', 'confirmo' => '1'])->getHeaderLine('Location'));
        $this->declare($ana, $key, 'media_registered');
        self::assertStringContainsString('Canal público', $this->page($ana));
        self::assertSame('channel_public', $this->dbRow($this->a, self::C_OWNER)['eligibility']);

        $this->add($ana, 'Canal que sigo');
        $this->add($ana, 'Canal sem papel');
        foreach ([self::C_FOLLOWER => 'channel_not_admin', self::C_UNKNOWN => 'insufficient_information'] as $jid => $reason) {
            $this->declare($ana, $this->keyOf($this->a, $jid), 'media_registered');
            self::assertSame($reason, $this->dbRow($this->a, $jid)['ineligible_reason']);
        }
        self::assertStringContainsString('Não elegível — seu número não administra este canal', $this->page($ana));
    }

    public function testSettingsNicheChangeWindowIntervalModeAndPause(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->eligibleOpenGroup($ana);

        self::assertSame('/destinos?erro=not_ready', $this->post($ana, "/destinos/$key/ativar")->getHeaderLine('Location'), 'Sem nicho não ativa.');
        self::assertSame('/destinos?ok=salvo', $this->configure($ana, $key, [])->getHeaderLine('Location'));
        $page = $this->page($ana);
        foreach (['Casa e cozinha', '09:00–21:00', 'A cada 45 min', 'Automático', 'Pausado'] as $text) {
            self::assertStringContainsString($text, $page);
        }
        self::assertSame(['air-fryers', 'panelas'], $this->subniches($key));

        self::assertSame('/destinos?ok=ativado', $this->post($ana, "/destinos/$key/ativar")->getHeaderLine('Location'));
        self::assertStringContainsString('<span class="pill ok">Ativo</span>', $this->page($ana));
        $this->post($ana, "/destinos/$key/pausar");
        self::assertSame(['paused', 1], [$this->dbRow($this->a, self::G_OPEN)['status'], $this->dbRow($this->a, self::G_OPEN)['user_paused']]);
        $this->post($ana, "/destinos/$key/ativar");
        self::assertSame('active', $this->dbRow($this->a, self::G_OPEN)['status']);

        // Troca de nicho: subnichos antigos saem; só vale subnicho ativo da conta e do nicho escolhido.
        $this->configure($ana, $key, ['nicho' => 'beleza-cuidados', 'subnichos' => ['perfumes'], 'modo' => 'manual']);
        self::assertSame(['perfumes'], $this->subniches($key));
        self::assertStringContainsString('Beleza e cuidados', $this->page($ana));
        self::assertSame('manual', $this->dbRow($this->a, self::G_OPEN)['mode']);
        foreach ([['subnichos' => ['air-fryers']], ['nicho' => 'beleza-cuidados', 'subnichos' => ['cafeteiras']], ['nicho' => 'eletronicos', 'subnichos' => ['fones']]] as $bad) {
            self::assertSame(422, $this->configure($ana, $key, ['nicho' => 'beleza-cuidados'] + $bad)->getStatusCode());
        }
        self::assertSame(['perfumes'], $this->subniches($key));

        // Desativar o subnicho na tela Nichos o retira do destino.
        (new AccountNicheRepository($this->db))->save($this->a->id, $this->nicheId('beleza-cuidados'), [], NicheFilters::defaults(), $this->ana, new \DateTimeImmutable());
        self::assertSame([], $this->subniches($key));
    }

    public function testInvalidWindowAndIntervalAreRejected(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->eligibleOpenGroup($ana);
        $this->configure($ana, $key, []);
        $before = $this->dbRow($this->a, self::G_OPEN);

        $cases = [
            [['inicio' => '22:00', 'fim' => '08:00'], 'O horário final precisa ser depois do inicial'],
            [['inicio' => '10:00', 'fim' => '10:15', 'intervalo' => '10'], 'pelo menos 30 minutos'],
            [['inicio' => '9h'], 'Use horários no formato 08:00'],
            [['intervalo' => '5'], 'Use um intervalo de 10 a 720 minutos'],
            [['intervalo' => '1000'], 'Use um intervalo de 10 a 720 minutos'],
            [['inicio' => '10:00', 'fim' => '11:00', 'intervalo' => '90'], 'não pode ser maior que a janela'],
            [['modo' => 'turbo'], 'Escolha automático ou manual'],
        ];
        foreach ($cases as [$override, $message]) {
            $response = $this->configure($ana, $key, $override);
            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString($message, (string) $response->getBody());
            self::assertStringContainsString('Nada foi salvo', (string) $response->getBody());
        }
        self::assertSame($before, $this->dbRow($this->a, self::G_OPEN));
    }

    public function testDisconnectedWhatsAppAndRemovedDestination(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->eligibleOpenGroup($ana);
        $this->configure($ana, $key, []);
        $this->post($ana, "/destinos/$key/ativar");

        // Grupo saiu do WhatsApp da conta: vira "Não elegível" e deixa de estar ativo.
        $this->uazapi->groups[$this->tokenA] = array_values(array_filter($this->uazapi->groups[$this->tokenA], static fn (array $g): bool => $g['JID'] !== self::G_OPEN));
        $this->post($ana, '/destinos/sincronizar');
        $row = $this->dbRow($this->a, self::G_OPEN);
        self::assertSame(['ineligible', 'not_found_in_whatsapp', 0], [$row['status'], $row['ineligible_reason'], $row['tech_present']]);
        self::assertStringContainsString('Não elegível — não aparece mais no seu WhatsApp', $this->page($ana));

        // WhatsApp desconectado: nada é consultado e a tela explica.
        $this->db->exec("UPDATE whatsapp_connections SET status = 'disconnected' WHERE installation_id = " . $this->a->id->value);
        $this->uazapi->requests = [];
        self::assertSame('/destinos?erro=whatsapp_not_connected', $this->post($ana, '/destinos/sincronizar')->getHeaderLine('Location'));
        self::assertSame('/destinos?erro=whatsapp_not_connected', $this->add($ana, 'Família')->getHeaderLine('Location'));
        self::assertSame([], $this->uazapi->requests);
        self::assertStringContainsString('Conecte o WhatsApp em', $this->page($ana));
    }

    public function testProviderFailuresOnSync(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->eligibleOpenGroup($ana);
        $before = $this->dbRow($this->a, self::G_OPEN);
        foreach ([[401, 'unauthorized', 'Reconecte em Conexões'], [403, 'forbidden', 'não permitiu a consulta'], [429, 'rate_limited', 'está no limite'], [500, 'server_error', 'instável'], [503, 'server_error', 'instável'], ['timeout', 'timeout', 'demorou demais'], ['invalid', 'invalid_response', 'forma inesperada']] as [$failure, $code, $text]) {
            $this->uazapi->fail('/group/list', $failure);
            self::assertSame('/destinos?erro=' . $code, $this->post($ana, '/destinos/sincronizar')->getHeaderLine('Location'));
            $page = (string) $this->httpGet('/destinos', ['sbm_session' => $ana], ['erro' => $code])->getBody();
            self::assertStringContainsString($text, $page);
            self::assertStringNotContainsString(FakeUazapiServer::RAW_PROVIDER_MESSAGE, $page);
            self::assertSame($before, $this->dbRow($this->a, self::G_OPEN), 'Falha não altera os destinos.');
        }
        // Falha ao reconsultar um grupo (5xx no /group/info) também não grava fatos parciais.
        $this->uazapi->fail('/group/info', 502);
        self::assertSame('/destinos?erro=server_error', $this->post($ana, '/destinos/sincronizar')->getHeaderLine('Location'));
        self::assertSame($before, $this->dbRow($this->a, self::G_OPEN));
        self::assertNotNull($key);
    }

    public function testCsrfAndSessionAreRequired(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->eligibleOpenGroup($ana);
        $before = $this->dbRow($this->a, self::G_OPEN);
        $this->uazapi->requests = [];
        $paths = ['/destinos/sincronizar', '/destinos/adicionar'];
        foreach (['configurar', 'pausar', 'ativar', 'declarar', 'remover', 'teste'] as $op) {
            $paths[] = "/destinos/$key/$op";
        }
        foreach ($paths as $path) {
            self::assertSame(400, $this->httpPost($path, [], ['sbm_session' => $ana])->getStatusCode(), $path);
            self::assertSame(400, $this->httpPost($path, ['_csrf' => 'forjado'], ['sbm_session' => $ana])->getStatusCode(), $path);
            $anonymous = $this->httpPost($path, ['_csrf' => 'x']);
            self::assertSame('/entrar', $anonymous->getHeaderLine('Location'), $path);
        }
        self::assertSame('/entrar', $this->httpGet('/destinos')->getHeaderLine('Location'));
        self::assertSame([], $this->uazapi->requests);
        self::assertSame($before, $this->dbRow($this->a, self::G_OPEN));
    }

    public function testAccountsNeverSeeOrChangeEachOthersDestinations(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $bia = $this->signIn('bia@loja-b.test', self::PASSWORD);
        $keyA = $this->eligibleOpenGroup($ana);
        $this->configure($ana, $keyA, []);
        $this->post($ana, "/destinos/$keyA/ativar");
        $this->post($ana, '/destinos/sincronizar');
        $pickA = $this->pickKey($this->page($ana), 'Família');
        $rowA = $this->dbRow($this->a, self::G_OPEN);

        // B sincroniza: só vê o próprio WhatsApp; não enxerga nada de A.
        $this->uazapi->requests = [];
        $this->post($bia, '/destinos/sincronizar');
        self::assertSame([$this->tokenB], $this->uazapi->tokensUsed());
        $pageB = $this->page($bia);
        self::assertStringContainsString('Grupo da B', $pageB);
        foreach (['Ofertas Casa', 'Família', 'Canal Ofertas A', $keyA, $pickA] as $secret) {
            self::assertStringNotContainsString($secret, $pageB);
        }

        // B tenta operar o destino de A pela chave: sempre "não encontrado" e A intacto.
        foreach (['configurar' => ['nicho' => 'casa-cozinha', 'subnichos' => ['cafeteiras'], 'inicio' => '09:00', 'fim' => '21:00', 'intervalo' => '45', 'modo' => 'auto'],
            'pausar' => [], 'ativar' => [], 'declarar' => ['tipo' => 'public_group', 'valor' => '0'], 'teste' => [], 'remover' => []] as $op => $body) {
            // Envio de teste desligado responde igual para qualquer chave (não revela se o destino existe).
            $expected = $op === 'teste' ? 'test_send_disabled' : 'not_found';
            self::assertSame('/destinos?erro=' . $expected, $this->post($bia, "/destinos/$keyA/$op", $body)->getHeaderLine('Location'), $op);
        }
        self::assertSame($rowA, $this->dbRow($this->a, self::G_OPEN));

        // B tenta cadastrar um destino de A: pela chave de escolha de A, pelo JID bruto ou com campos extras.
        foreach ([['escolha' => $pickA], ['escolha' => self::G_APPROVAL], ['escolha' => $pickA, 'installation_id' => (string) $this->a->id->value, 'provider_ref' => self::G_APPROVAL]] as $body) {
            self::assertSame('/destinos?erro=not_found', $this->post($bia, '/destinos/adicionar', $body)->getHeaderLine('Location'));
        }
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM destinations WHERE installation_id = ' . $this->b->id->value)->fetchColumn());
        // A sincronização de B não apagou a lista de disponíveis de A.
        self::assertStringContainsString('Família', $this->page($ana));
    }

    public function testProviderRefOfAnotherAccountIsRejectedByTheProvider(): void
    {
        $bia = $this->signIn('bia@loja-b.test', self::PASSWORD);
        // Simula um provider_ref de A "plantado" na lista de B: o cadastro reconsulta com o token de B e é recusado.
        $this->db->prepare("INSERT INTO whatsapp_available_destinations (installation_id, provider_ref, pick_key, type, name, fetched_at) VALUES (?, ?, ?, 'group', 'Plantado', NOW(3))")
            ->execute([$this->b->id->value, self::G_OPEN, str_repeat('ab', 10)]);
        $this->uazapi->requests = [];

        self::assertSame('/destinos?erro=not_found', $this->post($bia, '/destinos/adicionar', ['escolha' => str_repeat('ab', 10)])->getHeaderLine('Location'));
        self::assertSame([$this->tokenB], $this->uazapi->tokensUsed());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM destinations')->fetchColumn());
    }

    public function testTestSendIsOffByDefaultAndUsesFakeProviderWhenEnabled(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->eligibleOpenGroup($ana);
        self::assertStringNotContainsString('Enviar mensagem de teste', $this->page($ana));
        self::assertSame('/destinos?erro=test_send_disabled', $this->post($ana, "/destinos/$key/teste")->getHeaderLine('Location'));
        self::assertSame([], $this->uazapi->sent);

        $this->app = $this->build(['WHATSAPP_TEST_SEND_ENABLED' => 'true', 'WHATSAPP_TEST_IMAGE_URL' => 'https://sinergia.example.test/assets/teste.png']);
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        self::assertStringContainsString('Enviar mensagem de teste', $this->page($ana));
        self::assertSame('/destinos?ok=teste_enviado', $this->post($ana, "/destinos/$key/teste")->getHeaderLine('Location'));
        self::assertCount(1, $this->uazapi->sent);
        self::assertSame($this->tokenA, $this->uazapi->sent[0]['token']);
        self::assertStringContainsString(self::G_OPEN, $this->uazapi->sent[0]['body']);
        self::assertNotNull($this->dbRow($this->a, self::G_OPEN)['last_test_sent_at']);

        // Destino não elegível nunca recebe teste.
        $this->post($ana, '/destinos/sincronizar');
        $this->add($ana, 'Família');
        self::assertSame('/destinos?erro=not_eligible', $this->post($ana, '/destinos/' . $this->keyOf($this->a, self::G_APPROVAL) . '/teste')->getHeaderLine('Location'));
        self::assertCount(1, $this->uazapi->sent);
    }

    public function testRemove(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $key = $this->eligibleOpenGroup($ana);
        $this->configure($ana, $key, []);
        self::assertSame('/destinos?ok=removido', $this->post($ana, "/destinos/$key/remover")->getHeaderLine('Location'));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM destinations')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM destination_subniches')->fetchColumn());
        self::assertStringContainsString('Ofertas Casa', $this->page($ana), 'Volta para a lista de disponíveis.');
    }

    /** @param array<string, string> $extraEnv */
    private function build(array $extraEnv): App
    {
        $env = array_filter(array_merge($_ENV, getenv()), 'is_string');
        $container = Kernel::container(Config::fromArray($extraEnv + [
            'APP_ENV' => 'test',
            'APP_KEY' => $this->appKey,
            'DB_HOST' => (string) ($env['TEST_DB_HOST'] ?? ''),
            'DB_PORT' => (string) ($env['TEST_DB_PORT'] ?? '3306'),
            'DB_DATABASE' => (string) ($env['TEST_DB_DATABASE'] ?? ''),
            'DB_USERNAME' => (string) ($env['TEST_DB_USERNAME'] ?? ''),
            'DB_PASSWORD' => (string) ($env['TEST_DB_PASSWORD'] ?? ''),
            'UAZAPI_BASE_URL' => FakeUazapiServer::BASE_URL,
            'UAZAPI_ADMIN_TOKEN' => FakeUazapiServer::ADMIN_TOKEN,
        ]), dirname(__DIR__, 2));
        self::assertInstanceOf(Container::class, $container);
        $container->set('whatsapp.http', $this->uazapi->client());
        $container->set(LoggerInterface::class, LoggerFactory::create('local', $this->logs = new TestHandler()));

        return HttpApp::create($container);
    }

    private function connect(Installation $installation, string $token, int $userId): void
    {
        $repo = new WhatsAppConnectionRepository($this->db, new SecretBox(new SensitiveValue(base64_decode($this->appKey, true) ?: '')));
        $now = new \DateTimeImmutable();
        $repo->ensure($installation->id, 'uazapi', 'sbm-' . $installation->slug, $userId, $now);
        $repo->storeInstance($installation->id, new ProviderInstance(null, 'sbm-' . $installation->slug, new SensitiveValue($token)), $userId, $now);
        $repo->recordSnapshot($installation->id, new ConnectionSnapshot(ConnectionSnapshot::CONNECTED, phone: '5511987654321'), $now);
    }

    /** @param list<string> $subniches */
    private function chooseNiches(Installation $installation, int $userId, string $niche, array $subniches): void
    {
        $nicheId = $this->nicheId($niche);
        $ids = [];
        foreach ($subniches as $slug) {
            $stmt = $this->db->prepare('SELECT id FROM subniches WHERE niche_id = ? AND slug = ?');
            $stmt->execute([$nicheId, $slug]);
            $ids[] = (int) $stmt->fetchColumn();
        }
        (new AccountNicheRepository($this->db))->save($installation->id, $nicheId, $ids, NicheFilters::defaults(), $userId, new \DateTimeImmutable());
    }

    private function nicheId(string $slug): int
    {
        $stmt = $this->db->prepare('SELECT id FROM niches WHERE slug = ?');
        $stmt->execute([$slug]);

        return (int) $stmt->fetchColumn();
    }

    private function eligibleOpenGroup(string $session): string
    {
        $this->post($session, '/destinos/sincronizar');
        $this->add($session, 'Ofertas Casa');
        $key = $this->keyOf($this->a, self::G_OPEN);
        $this->declare($session, $key, 'public_group');
        $this->declare($session, $key, 'media_registered');

        return $key;
    }

    /** @param array<string, mixed> $override */
    private function configure(string $session, string $key, array $override): ResponseInterface
    {
        return $this->post($session, "/destinos/$key/configurar", $override + [
            'nicho' => 'casa-cozinha', 'subnichos' => ['air-fryers', 'panelas'], 'inicio' => '09:00', 'fim' => '21:00', 'intervalo' => '45', 'modo' => 'auto',
        ]);
    }

    private function declare(string $session, string $key, string $kind): void
    {
        self::assertSame('/destinos?ok=declarado', $this->post($session, "/destinos/$key/declarar", ['tipo' => $kind, 'valor' => '1', 'confirmo' => '1'])->getHeaderLine('Location'));
    }

    private function add(string $session, string $name): ResponseInterface
    {
        return $this->post($session, '/destinos/adicionar', ['escolha' => (string) $this->pickKey($this->page($session), $name)]);
    }

    /** @param array<string, mixed> $body */
    private function post(string $session, string $path, array $body = []): ResponseInterface
    {
        return $this->httpPost($path, ['_csrf' => $this->csrfFor($session, '/destinos')] + $body, ['sbm_session' => $session]);
    }

    private function page(string $session): string
    {
        return (string) $this->httpGet('/destinos', ['sbm_session' => $session])->getBody();
    }

    private function pickKey(string $html, string $name): ?string
    {
        return preg_match('#<strong>' . preg_quote($name, '#') . '</strong>.*?name="escolha" value="([a-f0-9]{20})"#s', $html, $m) === 1 ? $m[1] : null;
    }

    private function keyOf(Installation $installation, string $ref): string
    {
        return (string) $this->dbRow($installation, $ref)['public_key'];
    }

    /** @return array<string, mixed> */
    private function dbRow(Installation $installation, string $ref): array
    {
        $stmt = $this->db->prepare('SELECT * FROM destinations WHERE installation_id = ? AND provider_ref = ?');
        $stmt->execute([$installation->id->value, $ref]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? array_map(static fn (mixed $v): mixed => is_string($v) && ctype_digit($v) && strlen($v) < 10 ? (int) $v : $v, $row) : [];
    }

    /** @return list<string> */
    private function subniches(string $key): array
    {
        $stmt = $this->db->prepare('SELECT s.slug FROM destination_subniches ds JOIN destinations d ON d.id = ds.destination_id AND d.installation_id = ds.installation_id JOIN subniches s ON s.id = ds.subniche_id WHERE d.public_key = ? ORDER BY s.slug');
        $stmt->execute([$key]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function assertNothingTechnicalLeaked(string $html): void
    {
        self::assertStringNotContainsString('@g.us', $html);
        self::assertStringNotContainsString('@newsletter', $html);
        self::assertStringNotContainsString('TESTE-instancia', $html);
        self::assertStringNotContainsString(FakeUazapiServer::ADMIN_TOKEN, $html);
    }
}
