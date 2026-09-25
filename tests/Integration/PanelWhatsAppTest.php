<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use DI\Container;
use Monolog\Handler\TestHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\WhatsApp\ManageWhatsAppConnection;
use Sinergia\Application\WhatsApp\WhatsAppBusy;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Tests\Support\FakeUazapiServer;
use Sinergia\Tests\Support\PanelRequests;
use Sinergia\Web\HttpApp;

/**
 * Etapa 5: card WhatsApp (conexão por conta) com o container real, MariaDB de teste e Uazapi FALSA.
 * Nenhuma credencial real da Uazapi é usada.
 */
final class PanelWhatsAppTest extends DatabaseTestCase
{
    use PanelRequests;

    private const string PASSWORD = 'senha-do-painel-123';

    private \PDO $db;
    private string $appKey;
    private Installation $a;
    private Installation $b;
    private int $ana;
    private FakeUazapiServer $uazapi;
    private TestHandler $logs;
    private Container $container;
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
        $users->create($this->b->id, 'bia@loja-b.test', 'Bia', $hash);

        $this->appKey = SecretBox::generateKeyBase64();
        $this->uazapi = new FakeUazapiServer();
        $this->logs = new TestHandler();
        [$this->container, $this->app] = $this->build(withUazapi: true);
    }

    protected function panelApp(): App
    {
        return $this->app;
    }

    public function testInitialCardAndQrConnectionFlow(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $page = $this->page($ana);
        self::assertStringContainsString('Não conectado', $page);
        self::assertStringContainsString('Conectar com QR code', $page);
        self::assertSame([], $this->uazapi->requests, 'Sem conexão gravada, a página não chama o provedor.');

        $response = $this->connect($ana);
        self::assertSame('/conexoes?wa=conectando#whatsapp', $response->getHeaderLine('Location'));
        self::assertSame(['POST /instance/create', 'POST /instance/connect'], $this->uazapi->paths());
        self::assertSame(FakeUazapiServer::ADMIN_TOKEN, $this->uazapi->requests[0]['admintoken']);
        self::assertSame('', $this->uazapi->requests[1]['admintoken'], 'admintoken só na criação.');

        $row = $this->row($this->a);
        self::assertSame('connecting', $row['status']);
        self::assertSame('qr', $row['connect_mode']);
        $token = $this->uazapi->tokensUsed()[0];
        self::assertStringNotContainsString($token, (string) $row['instance_token_enc'], 'Token gravado cifrado.');
        self::assertSame($token, (new SecretBox(new SensitiveValue(base64_decode($this->appKey, true) ?: '')))->decrypt((string) $row['instance_token_enc'])->reveal());

        $first = $this->page($ana);
        self::assertStringContainsString('Aguardando conexão', $first);
        self::assertMatchesRegularExpression('#<img class="qr" src="data:image/png;base64,[A-Za-z0-9+/=]+"#', $first);
        self::assertMatchesRegularExpression('#<meta http-equiv="refresh" content="\d+;url=/conexoes\#whatsapp">#', $first);
        $second = $this->page($ana);
        self::assertNotSame($this->qr($first), $this->qr($second), 'Cada exibição usa o QR atual do provedor.');
        self::assertStringNotContainsString('QR-FALSO', json_encode($this->row($this->a), JSON_THROW_ON_ERROR) ?: '', 'QR nunca é gravado.');

        $this->uazapi->pair($token);
        $this->db->exec('UPDATE whatsapp_connections SET status_checked_at = NULL');
        $connected = $this->page($ana);
        self::assertStringContainsString('<span class="pill ok">Conectado</span>', $connected);
        self::assertStringContainsString('+55 (11) •••••-4321', $connected);
        self::assertStringNotContainsString('5511987654321', $connected, 'Número completo não aparece.');
        self::assertStringNotContainsString('data:image/png', $connected);
        self::assertStringNotContainsString('http-equiv="refresh"', $connected);
        self::assertSame('connected', $this->row($this->a)['status']);
        $this->assertNoSecretsLeaked($connected);
    }

    public function testPairingCodeFlowAndPhoneValidation(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);

        $invalid = $this->httpPost('/conexoes/whatsapp/conectar', ['_csrf' => $this->csrfFor($ana, '/conexoes'), 'modo' => 'codigo', 'telefone' => '123'], ['sbm_session' => $ana]);
        self::assertSame('/conexoes?wa=erro&motivo=telefone_invalido#whatsapp', $invalid->getHeaderLine('Location'));
        self::assertSame([], $this->uazapi->requests);

        $this->httpPost('/conexoes/whatsapp/conectar', ['_csrf' => $this->csrfFor($ana, '/conexoes'), 'modo' => 'codigo', 'telefone' => '+55 (11) 98765-4321'], ['sbm_session' => $ana]);
        self::assertSame('{"phone":"5511987654321"}', $this->uazapi->requests[1]['body']);
        self::assertSame('paircode', $this->row($this->a)['connect_mode']);
        $page = $this->page($ana);
        self::assertStringContainsString('<p class="paircode">ABCD-1234</p>', $page);
        self::assertStringNotContainsString('ABCD-1234', json_encode($this->row($this->a), JSON_THROW_ON_ERROR) ?: '', 'Código de pareamento nunca é gravado.');
    }

    public function testDoubleClickAndReconnectReuseTheSameInstance(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->connect($ana);
        $this->connect($ana);
        self::assertSame(1, $this->uazapi->count('POST /instance/create'));
        self::assertSame(1, $this->uazapi->count('POST /instance/connect'), 'Clique duplo dentro da validade não reinicia o fluxo.');

        $token = $this->uazapi->tokensUsed()[0];
        $this->uazapi->pair($token);
        $this->db->exec('UPDATE whatsapp_connections SET status_checked_at = NULL');
        $this->page($ana);

        $out = $this->httpPost('/conexoes/whatsapp/desconectar', ['_csrf' => $this->csrfFor($ana, '/conexoes')], ['sbm_session' => $ana]);
        self::assertSame('/conexoes?wa=desconectado#whatsapp', $out->getHeaderLine('Location'));
        self::assertSame('disconnected', $this->uazapi->instances[$token]['state']);
        self::assertStringContainsString('Desconectado', $this->page($ana));
        self::assertSame('disconnected', $this->row($this->a)['status']);

        $this->connect($ana);
        self::assertSame(1, $this->uazapi->count('POST /instance/create'), 'Reconexão reaproveita a instância da conta.');
        self::assertSame([$token], $this->uazapi->tokensUsed());
        self::assertStringContainsString('Aguardando conexão', $this->page($ana));
    }

    public function testRemoteDisconnectAndConflictAreHandled(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->connect($ana);
        $token = $this->uazapi->tokensUsed()[0];
        $this->uazapi->pair($token);
        $this->db->exec('UPDATE whatsapp_connections SET status_checked_at = NULL');
        self::assertStringContainsString('<span class="pill ok">Conectado</span>', $this->page($ana));

        // Dentro de 60 s o status conectado não é reconsultado a cada página.
        $this->uazapi->requests = [];
        $this->page($ana);
        self::assertSame([], $this->uazapi->requests);

        // O número foi desconectado pelo celular: na próxima checagem o card mostra "Desconectado".
        $this->uazapi->expire($token);
        $this->db->exec('UPDATE whatsapp_connections SET status_checked_at = NOW(3) - INTERVAL 2 MINUTE');
        $page = $this->page($ana);
        self::assertStringContainsString('<span class="pill">Desconectado</span>', $page);
        self::assertSame('disconnected', $this->row($this->a)['status']);

        // 409 (fluxo já em andamento no provedor): acompanha pelo status em vez de falhar.
        $this->uazapi->instances[$token]['state'] = 'connecting';
        $this->uazapi->fail('/instance/connect', 409);
        self::assertSame('/conexoes?wa=conectando#whatsapp', $this->connect($ana)->getHeaderLine('Location'));
        self::assertStringContainsString('Aguardando conexão', $this->page($ana));
    }

    public function testExpiredQrIsNeverShown(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->connect($ana);

        // Passou a janela de validade e o provedor ainda diz "connecting": nada de QR antigo nem novo.
        $this->db->exec('UPDATE whatsapp_connections SET connect_started_at = NOW(3) - INTERVAL 4 MINUTE');
        $page = $this->page($ana);
        self::assertStringContainsString('QR expirado', $page);
        self::assertStringNotContainsString('data:image/png', $page);
        self::assertStringContainsString('Gerar novo QR code', $page);

        // O provedor encerrou o fluxo (QR não lido): continua como expirado até gerar outro.
        $this->db->exec('UPDATE whatsapp_connections SET connect_started_at = NOW(3)');
        $this->uazapi->expire($this->uazapi->tokensUsed()[0]);
        self::assertStringContainsString('QR expirado', $this->page($ana));

        $this->connect($ana);
        self::assertStringContainsString('data:image/png', $this->page($ana), 'Novo fluxo gera novo QR.');
    }

    public function testInstanceRemovedAtProviderIsRecreatedOnlyForThisAccount(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->connect($ana);
        $old = $this->uazapi->tokensUsed()[0];
        $this->uazapi->delete($old);

        // A consulta de status recebe 401: o card mostra erro amigável, sem texto do provedor.
        $page = $this->page($ana);
        self::assertStringContainsString('<span class="pill bad">Erro</span>', $page);
        self::assertStringContainsString('O serviço de WhatsApp recusou a autenticação do servidor.', $page);

        $this->db->exec('UPDATE whatsapp_connections SET connect_started_at = NOW(3) - INTERVAL 10 MINUTE');
        $this->connect($ana);
        self::assertSame(2, $this->uazapi->count('POST /instance/create'));
        $new = $this->uazapi->tokensUsed()[1];
        self::assertNotSame($old, $new);
        self::assertSame($new, (new SecretBox(new SensitiveValue(base64_decode($this->appKey, true) ?: '')))->decrypt((string) $this->row($this->a)['instance_token_enc'])->reveal());
        self::assertStringContainsString('Aguardando conexão', $this->page($ana));
    }

    public function testProviderFailuresBecomeFriendlyMessages(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $cases = [
            [401, 'unauthorized', 'recusou a autenticação'],
            [403, 'forbidden', 'não permitiu esta operação'],
            [429, 'rate_limited', 'no limite de conexões'],
            [500, 'server_error', 'instável no momento'],
            [503, 'server_error', 'instável no momento'],
            ['timeout', 'timeout', 'demorou demais para responder'],
            ['invalid', 'invalid_response', 'respondeu de forma inesperada'],
        ];
        foreach ($cases as [$failure, $code, $text]) {
            $this->uazapi->fail('/instance/create', $failure);
            $response = $this->connect($ana);
            self::assertSame('/conexoes?wa=erro&motivo=' . $code . '#whatsapp', $response->getHeaderLine('Location'), (string) $failure);

            $page = (string) $this->httpGet('/conexoes', ['sbm_session' => $ana], ['wa' => 'erro', 'motivo' => $code])->getBody();
            self::assertStringContainsString($text, $page);
            self::assertStringContainsString('<span class="pill bad">Erro</span>', $page);
            self::assertStringNotContainsString(FakeUazapiServer::RAW_PROVIDER_MESSAGE, $page);
            self::assertNull($this->row($this->a)['instance_token_enc']);
        }
        self::assertStringNotContainsString(FakeUazapiServer::RAW_PROVIDER_MESSAGE, $this->logText());
        // Motivo arbitrário na URL não vira texto na página.
        $forged = (string) $this->httpGet('/conexoes', ['sbm_session' => $ana], ['wa' => 'erro', 'motivo' => '<script>x</script>'])->getBody();
        self::assertStringNotContainsString('<script>x', $forged);

        // Falha ao conectar uma instância existente (5xx no connect) e depois sucesso.
        $this->connect($ana);
        $this->db->exec('UPDATE whatsapp_connections SET connect_started_at = NOW(3) - INTERVAL 10 MINUTE');
        $this->uazapi->fail('/instance/connect', 502);
        self::assertStringContainsString('motivo=server_error', $this->connect($ana)->getHeaderLine('Location'));
        self::assertSame('server_error', $this->row($this->a)['last_error_code']);
        $this->connect($ana);
        self::assertNull($this->row($this->a)['last_error_code']);
    }

    public function testCsrfAndSessionAreRequired(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $csrfB = $this->csrfFor($this->signIn('bia@loja-b.test', self::PASSWORD), '/conexoes');
        foreach (['/conexoes/whatsapp/conectar', '/conexoes/whatsapp/desconectar'] as $path) {
            self::assertSame(400, $this->httpPost($path, [], ['sbm_session' => $ana])->getStatusCode());
            self::assertSame(400, $this->httpPost($path, ['_csrf' => 'forjado'], ['sbm_session' => $ana])->getStatusCode());
            self::assertSame(400, $this->httpPost($path, ['_csrf' => $csrfB], ['sbm_session' => $ana])->getStatusCode(), 'CSRF de outra conta.');
            $anonymous = $this->httpPost($path, ['_csrf' => 'x']);
            self::assertSame(302, $anonymous->getStatusCode());
            self::assertSame('/entrar', $anonymous->getHeaderLine('Location'));
        }
        self::assertSame([], $this->uazapi->requests);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM whatsapp_connections')->fetchColumn());
    }

    public function testAccountsNeverSeeOrControlEachOthersConnection(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $bia = $this->signIn('bia@loja-b.test', self::PASSWORD);
        $this->connect($ana);
        $tokenA = $this->uazapi->tokensUsed()[0];
        $qrA = $this->qr($this->page($ana));

        // B não vê o QR nem o estado de A, e sua página não consulta a instância de A.
        $this->uazapi->requests = [];
        $pageB = $this->page($bia);
        self::assertStringContainsString('Não conectado', $pageB);
        self::assertStringNotContainsString('data:image/png', $pageB);
        self::assertStringNotContainsString((string) $qrA, $pageB);
        self::assertSame([], $this->uazapi->requests);

        // B desconecta tentando apontar para A: nada acontece com A.
        $this->httpPost('/conexoes/whatsapp/desconectar', [
            '_csrf' => $this->csrfFor($bia, '/conexoes'), 'installation_id' => (string) $this->a->id->value, 'token' => $tokenA,
        ], ['sbm_session' => $bia]);
        self::assertSame('connecting', $this->uazapi->instances[$tokenA]['state']);
        self::assertNotContains($tokenA, $this->uazapi->tokensUsed());
        self::assertSame('connecting', $this->row($this->a)['status']);

        // B conecta: ganha a PRÓPRIA instância, com o próprio token.
        $this->uazapi->requests = [];
        $this->httpPost('/conexoes/whatsapp/conectar', ['_csrf' => $this->csrfFor($bia, '/conexoes'), 'installation_id' => (string) $this->a->id->value], ['sbm_session' => $bia]);
        self::assertSame(1, $this->uazapi->count('POST /instance/create'));
        $tokenB = $this->uazapi->tokensUsed()[0];
        self::assertNotSame($tokenA, $tokenB);
        self::assertSame('connecting', $this->row($this->b)['status']);

        // A desconecta: só a instância de A é afetada.
        $this->uazapi->requests = [];
        $this->httpPost('/conexoes/whatsapp/desconectar', ['_csrf' => $this->csrfFor($ana, '/conexoes')], ['sbm_session' => $ana]);
        self::assertSame([$tokenA], $this->uazapi->tokensUsed());
        self::assertSame('disconnected', $this->uazapi->instances[$tokenA]['state']);
        self::assertSame('connecting', $this->uazapi->instances[$tokenB]['state']);
        self::assertSame('connecting', $this->row($this->b)['status']);
        self::assertStringContainsString('Aguardando conexão', $this->page($bia));
    }

    public function testConcurrentConnectCreatesOnlyOneInstancePerAccount(): void
    {
        [$other] = $this->build(withUazapi: true);   // outro container = outra conexão ao banco (outra sessão)
        $tenantA = $this->tenant($this->a, $this->ana);
        $concurrent = null;
        $parallelB = null;
        $this->uazapi->before['/instance/create'] = function () use ($other, $tenantA, &$concurrent, &$parallelB): void {
            unset($this->uazapi->before['/instance/create']);
            try {
                $other->get(ManageWhatsAppConnection::class)->connect($tenantA);
                $concurrent = 'executou';
            } catch (WhatsAppBusy) {
                $concurrent = 'ocupado';
            }
            // A trava é por conta: B segue normalmente enquanto A está travada.
            $bia = (int) $this->db->query("SELECT id FROM users WHERE email = 'bia@loja-b.test'")->fetchColumn();
            $parallelB = $other->get(ManageWhatsAppConnection::class)->connect($this->tenant($this->b, $bia));
        };

        $outcome = $this->container->get(ManageWhatsAppConnection::class)->connect($tenantA);

        self::assertSame(ManageWhatsAppConnection::STARTED, $outcome);
        self::assertSame('ocupado', $concurrent, 'A segunda requisição da mesma conta não cria outra instância.');
        self::assertSame(ManageWhatsAppConnection::STARTED, $parallelB);
        self::assertSame(2, $this->uazapi->count('POST /instance/create'), 'Uma instância para A e uma para B.');
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM whatsapp_connections WHERE installation_id = ' . $this->a->id->value)->fetchColumn());
    }

    public function testUnavailableWhenUazapiIsNotConfigured(): void
    {
        [, $app] = $this->build(withUazapi: false);
        $this->app = $app;
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);

        self::assertStringContainsString('<span class="pill">Indisponível</span>', $this->page($ana));
        self::assertSame('/conexoes?wa=erro&motivo=unavailable#whatsapp', $this->connect($ana)->getHeaderLine('Location'));
        self::assertSame([], $this->uazapi->requests);
    }

    /** @return array{0: Container, 1: App} */
    private function build(bool $withUazapi): array
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
        ] + ($withUazapi ? ['UAZAPI_BASE_URL' => FakeUazapiServer::BASE_URL, 'UAZAPI_ADMIN_TOKEN' => FakeUazapiServer::ADMIN_TOKEN] : [])), dirname(__DIR__, 2));
        self::assertInstanceOf(Container::class, $container);
        $container->set('whatsapp.http', $this->uazapi->client());
        $container->set(LoggerInterface::class, LoggerFactory::create('local', $this->logs));

        return [$container, HttpApp::create($container)];
    }

    private function tenant(Installation $installation, int $userId): TenantContext
    {
        return new TenantContext($installation->id, $installation->name, $userId, 'Usuário', 'usuario@teste.test');
    }

    private function connect(string $session): ResponseInterface
    {
        return $this->httpPost('/conexoes/whatsapp/conectar', ['_csrf' => $this->csrfFor($session, '/conexoes'), 'modo' => 'qr'], ['sbm_session' => $session]);
    }

    private function page(string $session): string
    {
        return (string) $this->httpGet('/conexoes', ['sbm_session' => $session])->getBody();
    }

    private function qr(string $html): ?string
    {
        return preg_match('#src="(data:image/png;base64,[^"]+)"#', $html, $m) === 1 ? $m[1] : null;
    }

    /** @return array<string, mixed> */
    private function row(Installation $installation): array
    {
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_connections WHERE installation_id = ?');
        $stmt->execute([$installation->id->value]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function logText(): string
    {
        return implode("\n", array_map(static fn ($r): string => (string) json_encode($r->toArray()), $this->logs->getRecords()));
    }

    private function assertNoSecretsLeaked(string $html): void
    {
        $haystack = $html . "\n" . $this->logText();
        self::assertStringNotContainsString(FakeUazapiServer::ADMIN_TOKEN, $haystack);
        foreach ($this->uazapi->tokensUsed() as $token) {
            self::assertStringNotContainsString($token, $haystack);
        }
        foreach ($this->uazapi->requests as $request) {
            self::assertStringNotContainsString('token', strtolower((string) parse_url($request['uri'], PHP_URL_QUERY)), 'Nenhum token na URL.');
        }
    }
}
