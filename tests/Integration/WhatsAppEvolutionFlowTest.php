<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use DI\Container;
use Monolog\Handler\TestHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Cli\Command\WhatsAppInstanceAssignCommand;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Tests\Support\FakeEvolutionServer;
use Sinergia\Tests\Support\PanelRequests;
use Sinergia\Web\HttpApp;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Evolution API 2.3.7 (FALSA), opção C: a instância é criada fora do BotML; o comando whatsapp:instance:assign grava
 * só a credencial da própria instância (cifrada); o painel mantém "Conectar WhatsApp → QR → Conectado".
 * Nenhuma credencial real; a chave global da Evolution nunca é enviada.
 */
final class WhatsAppEvolutionFlowTest extends DatabaseTestCase
{
    use PanelRequests;

    private const string PASSWORD = 'senha-do-painel-123';
    private const string INSTANCE = 'sbm-1-botml';

    private \PDO $db;
    private string $appKey;
    private Installation $a;
    private Installation $b;
    private FakeEvolutionServer $evolution;
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
        $users->create($this->a->id, 'ana@loja-a.test', 'Ana', $hash);
        $users->create($this->b->id, 'bia@loja-b.test', 'Bia', $hash);

        $this->appKey = SecretBox::generateKeyBase64();
        $this->evolution = new FakeEvolutionServer();
        $this->logs = new TestHandler();
        [$this->container, $this->app] = $this->build(['WHATSAPP_PROVIDER' => 'evolution', 'EVOLUTION_BASE_URL' => FakeEvolutionServer::BASE_URL]);
    }

    protected function panelApp(): App
    {
        return $this->app;
    }

    public function testAssignCommandStoresOnlyTheInstanceCredentialEncrypted(): void
    {
        $token = $this->evolution->instance(self::INSTANCE);
        $tester = $this->assign('conta-a', self::INSTANCE, $token);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Instância "sbm-1-botml" atribuída à conta "Loja A" (conta-a)', $display);
        self::assertStringNotContainsString($token, $display, 'O token nunca é exibido.');

        $row = $this->row($this->a);
        self::assertSame(['evolution', self::INSTANCE, 'disconnected', null], [$row['provider'], $row['instance_name'], $row['status'], $row['updated_by_user_id']]);
        self::assertStringNotContainsString($token, (string) $row['instance_token_enc'], 'Gravado cifrado.');
        self::assertStringNotContainsString(self::INSTANCE, (string) $row['instance_token_enc']);
        self::assertSame('evo1:' . self::INSTANCE . ':' . $token, $this->box()->decrypt((string) $row['instance_token_enc'])->reveal());
        self::assertSame([], $this->evolution->requests, 'Atribuir não chama a Evolution.');
        self::assertStringNotContainsString($token, $this->logText());
    }

    public function testAssignCommandRefusesUnsafeOrAmbiguousAssignments(): void
    {
        $token = $this->evolution->instance(self::INSTANCE);
        self::assertSame(Command::FAILURE, $this->assign('nao-existe', self::INSTANCE, $token)->getStatusCode());
        self::assertSame(Command::INVALID, $this->assign('conta-a', 'Instancia_Invalida', $token)->getStatusCode());
        self::assertSame(Command::INVALID, $this->assign('conta-a', self::INSTANCE, null)->getStatusCode(), 'Digitações diferentes/vazias.');
        $bad = $this->assign('conta-a', self::INSTANCE, 'token com espaço e : dois-pontos');
        self::assertSame(Command::INVALID, $bad->getStatusCode());
        self::assertStringNotContainsString('dois-pontos', $bad->getDisplay());
        self::assertSame([], $this->rows('SELECT * FROM whatsapp_connections'), 'Nada gravado nas recusas.');

        self::assertSame(Command::SUCCESS, $this->assign('conta-a', self::INSTANCE, $token)->getStatusCode());
        $other = $this->assign('conta-b', self::INSTANCE, $token);
        self::assertSame(Command::FAILURE, $other->getStatusCode(), 'A mesma instância não pode servir duas contas.');
        self::assertStringContainsString('já está atribuída a outra conta', $other->getDisplay());
        self::assertSame([], $this->rows('SELECT * FROM whatsapp_connections WHERE installation_id = ?', [$this->b->id->value]));

        // Conta conectada: não troca a instância sem desconectar antes.
        $this->db->exec("UPDATE whatsapp_connections SET status = 'connected' WHERE installation_id = " . $this->a->id->value);
        $second = $this->evolution->instance('sbm-1-outra');
        self::assertSame(Command::FAILURE, $this->assign('conta-a', 'sbm-1-outra', $second)->getStatusCode());
        $this->db->exec("UPDATE whatsapp_connections SET status = 'disconnected' WHERE installation_id = " . $this->a->id->value);
        self::assertSame(Command::SUCCESS, $this->assign('conta-a', 'sbm-1-outra', $second)->getStatusCode(), 'Desconectada: pode trocar.');
        self::assertSame([['instance_name' => 'sbm-1-outra']], $this->rows('SELECT instance_name FROM whatsapp_connections WHERE installation_id = ?', [$this->a->id->value]));

        // Servidor configurado para outro provedor: o comando se recusa.
        [$uazapiContainer] = $this->build([]);
        $tester = new CommandTester(new WhatsAppInstanceAssignCommand($uazapiContainer, static fn (): SensitiveValue => new SensitiveValue($second)));
        self::assertSame(Command::FAILURE, $tester->execute(['--installation' => 'conta-b', '--instance' => 'sbm-2-nova']));
    }

    public function testAccountWithoutAssignedInstanceIsNotReleasedAndNeverCallsEvolution(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $page = $this->page($ana);
        self::assertStringContainsString('<span class="pill">Não liberado</span>', $page);
        self::assertStringContainsString('WhatsApp ainda não está liberado para esta conta.', $page);
        self::assertStringNotContainsString('Conectar com QR code', $page);
        self::assertStringContainsString('usa a Evolution API, software de código aberto de terceiros', $page, 'Aviso de uso (licença 2.3.7, 1.b).');

        self::assertSame('/conexoes?wa=erro&motivo=nao_liberado#whatsapp', $this->connect($ana)->getHeaderLine('Location'));
        self::assertStringContainsString('WhatsApp ainda não está liberado para esta conta.', (string) $this->httpGet('/conexoes', ['sbm_session' => $ana], ['wa' => 'erro', 'motivo' => 'nao_liberado'])->getBody());
        self::assertSame([], $this->evolution->requests, 'Nunca tenta criar instância.');
        self::assertSame([], $this->rows('SELECT * FROM whatsapp_connections'));
    }

    public function testConnectQrConnectedDisconnectAndReconnectWithTheAssignedInstance(): void
    {
        $token = $this->evolution->instance(self::INSTANCE);
        $this->assign('conta-a', self::INSTANCE, $token);
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);

        $initial = $this->page($ana);
        self::assertStringContainsString('Conectar com QR code', $initial);
        self::assertSame([], $this->evolution->requests, 'Sem conexão iniciada, a página não chama a Evolution.');

        self::assertSame('/conexoes?wa=conectando#whatsapp', $this->connect($ana)->getHeaderLine('Location'));
        self::assertSame(['GET /instance/connect'], $this->evolution->paths());
        self::assertSame(['connecting', 'qr', self::INSTANCE], [$this->row($this->a)['status'], $this->row($this->a)['connect_mode'], $this->row($this->a)['instance_name']]);

        $waiting = $this->page($ana);
        self::assertStringContainsString('Aguardando conexão', $waiting);
        self::assertMatchesRegularExpression('#<img class="qr" src="data:image/png;base64,[A-Za-z0-9+/=]+"#', $waiting);

        $this->evolution->pair(self::INSTANCE);
        $connected = $this->page($ana);
        self::assertStringContainsString('<span class="pill ok">Conectado</span>', $connected);
        self::assertStringContainsString('+55 (11) •••••-4321', $connected);
        self::assertStringNotContainsString('5511987654321', $connected);
        self::assertSame('connected', $this->row($this->a)['status']);

        $this->httpPost('/conexoes/whatsapp/desconectar', ['_csrf' => $this->csrfFor($ana, '/conexoes')], ['sbm_session' => $ana]);
        self::assertSame('close', $this->evolution->instances[self::INSTANCE]['state']);
        $row = $this->row($this->a);
        self::assertSame('disconnected', $row['status']);
        self::assertNotNull($row['instance_token_enc'], 'Desconectar mantém a credencial (a instância continua válida).');

        // Reconexão com a MESMA instância: novo QR, nenhuma criação.
        self::assertSame('/conexoes?wa=conectando#whatsapp', $this->connect($ana)->getHeaderLine('Location'));
        self::assertMatchesRegularExpression('#<img class="qr" src="data:image/png;base64,#', $this->page($ana));

        foreach ($this->evolution->requests as $request) {
            self::assertSame($token, $request['apikey']);
            self::assertStringNotContainsString($token, $request['path'] . '?' . $request['query']);
        }
        self::assertSame(0, count(array_filter($this->evolution->paths(), static fn (string $p): bool => str_contains($p, '/instance/create'))));
        $this->assertNoSecretsLeaked($token, $connected . $waiting . $initial);
    }

    public function testInstanceRemovedFromEvolutionClearsTheCredentialAndBlocksUntilReassigned(): void
    {
        $token = $this->evolution->instance(self::INSTANCE);
        $this->assign('conta-a', self::INSTANCE, $token);
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->evolution->remove(self::INSTANCE);

        self::assertSame('/conexoes?wa=erro&motivo=nao_liberado#whatsapp', $this->connect($ana)->getHeaderLine('Location'));
        $row = $this->row($this->a);
        self::assertNull($row['instance_token_enc'], 'Credencial inválida é esquecida.');
        self::assertStringContainsString('WhatsApp ainda não está liberado para esta conta.', $this->page($ana));
        self::assertSame(['GET /instance/connect'], $this->evolution->paths(), 'Uma tentativa; nenhuma criação.');
        $this->assertNoSecretsLeaked($token, '');
    }

    public function testAccountsAreIsolated(): void
    {
        $token = $this->evolution->instance(self::INSTANCE);
        $this->assign('conta-a', self::INSTANCE, $token);
        $this->evolution->pair(self::INSTANCE);
        $this->connect($this->signIn('ana@loja-a.test', self::PASSWORD));
        $requestsBefore = count($this->evolution->requests);

        $bia = $this->signIn('bia@loja-b.test', self::PASSWORD);
        $page = $this->page($bia);
        self::assertStringContainsString('WhatsApp ainda não está liberado para esta conta.', $page);
        self::assertStringNotContainsString('•••', $page, 'B não vê o número conectado de A.');
        self::assertSame('/conexoes?wa=erro&motivo=nao_liberado#whatsapp', $this->connect($bia)->getHeaderLine('Location'));
        self::assertCount($requestsBefore, $this->evolution->requests, 'Ações de B nunca usam a instância de A.');
        self::assertSame([], $this->rows('SELECT * FROM whatsapp_connections WHERE installation_id = ?', [$this->b->id->value]));
    }

    /** @param array<string, string> $whatsApp @return array{0: Container, 1: App} */
    private function build(array $whatsApp): array
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
        ] + $whatsApp), dirname(__DIR__, 2));
        self::assertInstanceOf(Container::class, $container);
        $container->set('whatsapp.http', $this->evolution->client());
        $container->set(LoggerInterface::class, LoggerFactory::create('local', $this->logs));

        return [$container, HttpApp::create($container)];
    }

    private function assign(string $slug, string $instance, ?string $token): CommandTester
    {
        $tester = new CommandTester(new WhatsAppInstanceAssignCommand($this->container, static fn (): ?SensitiveValue => $token === null ? null : new SensitiveValue($token)));
        $tester->execute(['--installation' => $slug, '--instance' => $instance]);

        return $tester;
    }

    private function connect(string $session): ResponseInterface
    {
        return $this->httpPost('/conexoes/whatsapp/conectar', ['_csrf' => $this->csrfFor($session, '/conexoes'), 'modo' => 'qr'], ['sbm_session' => $session]);
    }

    private function page(string $session): string
    {
        return (string) $this->httpGet('/conexoes', ['sbm_session' => $session])->getBody();
    }

    private function box(): SecretBox
    {
        return new SecretBox(new SensitiveValue(base64_decode($this->appKey, true) ?: ''));
    }

    /** @return array<string, mixed> */
    private function row(Installation $installation): array
    {
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_connections WHERE installation_id = ?');
        $stmt->execute([$installation->id->value]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /** @param list<mixed> $params @return list<array<string, mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function logText(): string
    {
        return implode("\n", array_map(static fn ($r): string => (string) json_encode($r->toArray()), $this->logs->getRecords()));
    }

    private function assertNoSecretsLeaked(string $token, string $html): void
    {
        $haystack = $html . "\n" . $this->logText();
        self::assertStringNotContainsString($token, $haystack);
        self::assertStringNotContainsString(FakeEvolutionServer::GLOBAL_KEY, $haystack);
        self::assertStringNotContainsString(FakeEvolutionServer::RAW_PROVIDER_MESSAGE, $haystack);
        foreach ($this->evolution->requests as $request) {
            self::assertNotSame(FakeEvolutionServer::GLOBAL_KEY, $request['apikey'], 'A chave global nunca é enviada.');
        }
    }
}
