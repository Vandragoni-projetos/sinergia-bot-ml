<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use DI\Container;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Application\Port\MercadoLivre\TokenSet;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Infrastructure\Persistence\OAuthStateRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthClient;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\MercadoLivreConfig;
use Sinergia\Shared\Config\MercadoLivreOAuthConfig;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Tests\Support\FakeMercadoLivre;
use Sinergia\Tests\Support\PanelRequests;
use Sinergia\Web\HttpApp;

/**
 * Etapa 2: conexão Mercado Livre pelo painel, com foco em isolamento entre contas.
 * Container real do Kernel + MariaDB de teste; só o HTTP do Mercado Livre é falso.
 */
final class PanelMercadoLivreConnectionTest extends DatabaseTestCase
{
    use PanelRequests;

    private const string PASSWORD = 'senha-do-painel-123';
    private const string CLIENT_ID = '1234567890123456';
    private const string SECRET = 'fake-client-secret-for-tests-only';
    private const string CODE = 'TG-fake-authorization-code-0001';
    private const string REFRESH = 'TG-fake-refresh-token-0001';

    private \PDO $db;
    private Installation $a;
    private Installation $b;
    private FakeMercadoLivre $ml;
    private Container $container;
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->freshSchema();
        $installations = new InstallationRepository($this->db);
        $this->a = $installations->ensure('conta-a', 'Loja A', 'MLB');
        $this->b = $installations->ensure('conta-b', 'Loja B', 'MLB');
        $hasher = new PasswordHasher();
        $users = new UserRepository($this->db);
        $users->create($this->a->id, 'ana@loja-a.test', 'Ana', $hasher->hash(new SensitiveValue(self::PASSWORD)));
        $users->create($this->b->id, 'bia@loja-b.test', 'Bia', $hasher->hash(new SensitiveValue(self::PASSWORD)));

        $this->ml = new FakeMercadoLivre(withToken: false);
        [$this->container, $this->app] = $this->buildApp(withMlOAuth: true);
    }

    protected function panelApp(): App
    {
        return $this->app;
    }

    public function testConnectionsPageStartsDisconnectedWithAffiliateBlock(): void
    {
        $body = (string) $this->httpGet('/conexoes', ['sbm_session' => $this->signIn('ana@loja-a.test', self::PASSWORD)])->getBody();

        self::assertStringContainsString('Não conectado', $body);
        self::assertStringContainsString('Conectar Mercado Livre', $body);
        self::assertStringContainsString('Gerador oficial em lote', $body);
        self::assertStringContainsString('cadastrados como Mídia', $body);
        self::assertStringContainsString('WhatsApp', $body);
    }

    public function testConnectRequiresCsrf(): void
    {
        $session = $this->signIn('ana@loja-a.test', self::PASSWORD);

        self::assertSame(400, $this->httpPost('/conexoes/mercadolivre/conectar', ['_csrf' => 'forjado'], ['sbm_session' => $session])->getStatusCode());
        self::assertSame(0, $this->rows('ml_oauth_states'));
        self::assertSame(302, $this->httpPost('/conexoes/mercadolivre/conectar', ['_csrf' => 'x'])->getStatusCode(), 'Sem sessão → /entrar');
    }

    public function testConnectStartsOAuthBoundToTheAuthenticatedAccount(): void
    {
        $session = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $response = $this->startConnection($session);

        $location = $response->getHeaderLine('Location');
        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('https://auth.mercadolivre.com.br/authorization?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame(self::CLIENT_ID, $query['client_id']);
        self::assertSame('https://app.example.test/oauth/mercadolivre/callback', $query['redirect_uri']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertStringNotContainsString(self::SECRET, $location);

        $row = $this->db->query('SELECT installation_id, origin, started_by_user_id, state_hash FROM ml_oauth_states')->fetch();
        self::assertSame($this->a->id->value, (int) $row['installation_id'], 'State vinculado à conta autenticada');
        self::assertSame('panel', $row['origin']);
        self::assertSame(hash('sha256', (string) $query['state']), $row['state_hash'], 'Banco guarda só o hash do state');

        self::assertStringContainsString('Conectando', (string) $this->httpGet('/conexoes', ['sbm_session' => $session])->getBody());
    }

    public function testCallbackConnectsTheSameAccountAndNeverLeaksSecrets(): void
    {
        $session = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $state = $this->stateFrom($this->startConnection($session));
        $this->queueTokens(1234567);

        $callback = $this->httpGet('/oauth/mercadolivre/callback', ['sbm_session' => $session], ['code' => self::CODE, 'state' => $state]);

        self::assertSame(302, $callback->getStatusCode());
        self::assertSame('/conexoes?ml=conectado', $callback->getHeaderLine('Location'));
        self::assertSame([$this->a->id->value], $this->credentialOwners());
        parse_str((string) $this->ml->lastRequest()->getBody(), $form);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertNotEmpty($form['code_verifier'], 'PKCE enviado');

        $page = $this->httpGet('/conexoes', ['sbm_session' => $session], ['ml' => 'conectado']);
        $body = (string) $page->getBody();
        self::assertStringContainsString('Mercado Livre conectado.', $body);
        self::assertStringContainsString('nº 1234567', $body);
        self::assertStringContainsString('Ativa', $body);
        foreach ([FakeMercadoLivre::TOKEN, self::REFRESH, self::CODE, $state, self::SECRET] as $secret) {
            self::assertStringNotContainsString($secret, $body);
            self::assertStringNotContainsString($secret, $callback->getHeaderLine('Location'));
            self::assertStringNotContainsString($secret, $this->ml->logText());
        }
    }

    public function testStateStartedByOneAccountCannotBeCompletedByAnother(): void
    {
        $state = $this->stateFrom($this->startConnection($this->signIn('ana@loja-a.test', self::PASSWORD)));
        $sessionB = $this->signIn('bia@loja-b.test', self::PASSWORD);
        $this->queueTokens(1234567);

        $callback = $this->httpGet('/oauth/mercadolivre/callback', ['sbm_session' => $sessionB], ['code' => self::CODE, 'state' => $state]);

        self::assertSame('/conexoes?ml=erro&motivo=account_mismatch', $callback->getHeaderLine('Location'));
        self::assertSame([], $this->credentialOwners(), 'Nenhuma conta recebeu credenciais');
        self::assertSame([], $this->ml->history, 'O code nem foi trocado');
        self::assertSame(0, $this->rows('ml_oauth_states'), 'State queimado (uso único)');
        self::assertStringContainsString('iniciada por outra conta', (string) $this->httpGet('/conexoes', ['sbm_session' => $sessionB], ['ml' => 'erro', 'motivo' => 'account_mismatch'])->getBody());
    }

    public function testPanelStateRequiresASignedInSession(): void
    {
        $state = $this->stateFrom($this->startConnection($this->signIn('ana@loja-a.test', self::PASSWORD)));

        $callback = $this->httpGet('/oauth/mercadolivre/callback', [], ['code' => self::CODE, 'state' => $state]);

        self::assertSame(400, $callback->getStatusCode());
        self::assertStringContainsString('login_required', (string) $callback->getBody());
        self::assertSame([], $this->credentialOwners());
        self::assertSame([], $this->ml->history);
    }

    public function testForgedStateIsRejected(): void
    {
        $session = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->startConnection($session);

        $callback = $this->httpGet('/oauth/mercadolivre/callback', ['sbm_session' => $session], ['code' => self::CODE, 'state' => 'state-inventado-pelo-atacante']);

        self::assertSame('/conexoes?ml=erro&motivo=state_unknown', $callback->getHeaderLine('Location'));
        self::assertSame([], $this->credentialOwners());
        self::assertSame(1, $this->rows('ml_oauth_states'), 'O state legítimo continua pendente');
    }

    public function testAccountsNeverSeeOrReplaceEachOthersConnection(): void
    {
        // B já conectada (credencial própria).
        $this->container->get(MlCredentialRepository::class)->save($this->b->id, self::CLIENT_ID, new TokenSet(
            new SensitiveValue('APP_USR-token-da-conta-b'), new SensitiveValue('TG-refresh-da-conta-b'), 21600, 'offline_access read', 999, 'bearer',
        ), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $sessionA = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $sessionB = $this->signIn('bia@loja-b.test', self::PASSWORD);
        self::assertStringContainsString('Não conectado', (string) $this->httpGet('/conexoes', ['sbm_session' => $sessionA])->getBody(), 'A não vê a conexão de B');

        // A conecta duas vezes (conectar e reconectar).
        foreach ([1234567, 1234567] as $mlUser) {
            $state = $this->stateFrom($this->startConnection($sessionA));
            $this->queueTokens($mlUser);
            $this->httpGet('/oauth/mercadolivre/callback', ['sbm_session' => $sessionA], ['code' => self::CODE, 'state' => $state]);
        }

        $pageA = (string) $this->httpGet('/conexoes', ['sbm_session' => $sessionA])->getBody();
        $pageB = (string) $this->httpGet('/conexoes', ['sbm_session' => $sessionB])->getBody();
        self::assertStringContainsString('nº 1234567', $pageA);
        self::assertStringNotContainsString('nº 999', $pageA);
        self::assertStringContainsString('nº 999', $pageB);
        self::assertStringNotContainsString('nº 1234567', $pageB);

        $versions = $this->db->query('SELECT installation_id, version FROM ml_credentials ORDER BY installation_id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame(2, (int) $versions[$this->a->id->value], 'A reconectou no próprio registro');
        self::assertSame(1, (int) $versions[$this->b->id->value], 'Registro de B intocado');
        $b = $this->container->get(MlCredentialRepository::class)->find($this->b->id);
        self::assertSame('APP_USR-token-da-conta-b', $b?->accessToken->reveal(), 'Token de B continua o mesmo');
    }

    public function testMediaDeclarationIsPersistedPerAccountAndUser(): void
    {
        $sessionA = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $sessionB = $this->signIn('bia@loja-b.test', self::PASSWORD);
        $csrfA = $this->csrfFor($sessionA, '/conexoes');

        $unconfirmed = $this->httpPost('/conexoes/afiliado/declaracao', ['_csrf' => $csrfA, 'acao' => 'declarar'], ['sbm_session' => $sessionA]);
        self::assertSame('/conexoes?afiliado=confirmacao_necessaria', $unconfirmed->getHeaderLine('Location'));
        self::assertSame(0, $this->rows('affiliate_media_declarations'));
        self::assertSame(400, $this->httpPost('/conexoes/afiliado/declaracao', ['_csrf' => 'forjado', 'acao' => 'declarar', 'confirmo' => '1'], ['sbm_session' => $sessionA])->getStatusCode());

        $this->httpPost('/conexoes/afiliado/declaracao', ['_csrf' => $csrfA, 'acao' => 'declarar', 'confirmo' => '1'], ['sbm_session' => $sessionA]);
        $row = $this->db->query('SELECT installation_id, user_id, declaration_version, revoked_at FROM affiliate_media_declarations')->fetch();
        self::assertSame($this->a->id->value, (int) $row['installation_id']);
        self::assertSame('v1', $row['declaration_version']);
        self::assertNull($row['revoked_at']);
        self::assertStringContainsString('Mídias declaradas', (string) $this->httpGet('/conexoes', ['sbm_session' => $sessionA])->getBody());
        self::assertStringContainsString('Registrar declaração', (string) $this->httpGet('/conexoes', ['sbm_session' => $sessionB])->getBody(), 'B não herda a declaração de A');

        // B removendo "a declaração" só afeta a própria conta.
        $this->httpPost('/conexoes/afiliado/declaracao', ['_csrf' => $this->csrfFor($sessionB, '/conexoes'), 'acao' => 'remover'], ['sbm_session' => $sessionB]);
        self::assertStringContainsString('Mídias declaradas', (string) $this->httpGet('/conexoes', ['sbm_session' => $sessionA])->getBody());

        $this->httpPost('/conexoes/afiliado/declaracao', ['_csrf' => $csrfA, 'acao' => 'remover'], ['sbm_session' => $sessionA]);
        self::assertNotNull($this->db->query('SELECT revoked_at FROM affiliate_media_declarations')->fetchColumn());
        self::assertStringContainsString('Registrar declaração', (string) $this->httpGet('/conexoes', ['sbm_session' => $sessionA])->getBody());
    }

    public function testTerminalStartedStateStillCompletesWithoutPanelSession(): void
    {
        $state = new SensitiveValue('state-criado-pelo-terminal-0001');
        (new OAuthStateRepository($this->db, $this->container->get(SecretBox::class)))->create(
            $this->a->id, $state, new SensitiveValue(str_repeat('v', 86)), new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')),
        );
        $this->queueTokens(1234567);

        $callback = $this->httpGet('/oauth/mercadolivre/callback', [], ['code' => self::CODE, 'state' => $state->reveal()]);

        self::assertSame(200, $callback->getStatusCode());
        self::assertStringContainsString('Mercado Livre conectado com sucesso.', (string) $callback->getBody());
        self::assertSame([$this->a->id->value], $this->credentialOwners());
    }

    public function testMissingOAuthConfigurationShowsUnavailable(): void
    {
        [, $this->app] = $this->buildApp(withMlOAuth: false);
        $session = $this->signIn('ana@loja-a.test', self::PASSWORD);

        $response = $this->startConnection($session);

        self::assertSame('/conexoes?ml=erro&motivo=unavailable', $response->getHeaderLine('Location'));
        self::assertSame(0, $this->rows('ml_oauth_states'));
    }

    /** @return array{0: Container, 1: App} */
    private function buildApp(bool $withMlOAuth): array
    {
        $env = array_filter(array_merge($_ENV, getenv()), 'is_string');
        $config = Config::fromArray([
            'APP_ENV' => 'test',
            'APP_KEY' => SecretBox::generateKeyBase64(),
            'DB_HOST' => (string) ($env['TEST_DB_HOST'] ?? ''),
            'DB_PORT' => (string) ($env['TEST_DB_PORT'] ?? '3306'),
            'DB_DATABASE' => (string) ($env['TEST_DB_DATABASE'] ?? ''),
            'DB_USERNAME' => (string) ($env['TEST_DB_USERNAME'] ?? ''),
            'DB_PASSWORD' => (string) ($env['TEST_DB_PASSWORD'] ?? ''),
        ] + ($withMlOAuth ? [
            'ML_CLIENT_ID' => self::CLIENT_ID,
            'ML_CLIENT_SECRET' => self::SECRET,
            'ML_REDIRECT_URI' => 'https://app.example.test/oauth/mercadolivre/callback',
        ] : []));

        $container = Kernel::container($config, dirname(__DIR__, 2));
        self::assertInstanceOf(Container::class, $container);
        if ($withMlOAuth) {
            // Só o HTTP do Mercado Livre é substituído; todo o resto é o container real.
            $container->set(OAuthClient::class, new OAuthClient(
                $this->ml->client,
                new MercadoLivreConfig('MLB', 'https://api.mercadolibre.com', 'https://auth.mercadolivre.com.br', 5, 'test'),
                new MercadoLivreOAuthConfig(self::CLIENT_ID, new SensitiveValue(self::SECRET), 'https://app.example.test/oauth/mercadolivre/callback', true),
            ));
        }

        return [$container, HttpApp::create($container)];
    }

    private function startConnection(string $session): ResponseInterface
    {
        return $this->httpPost('/conexoes/mercadolivre/conectar', ['_csrf' => $this->csrfFor($session, '/conexoes')], ['sbm_session' => $session]);
    }

    private function stateFrom(ResponseInterface $start): string
    {
        parse_str((string) parse_url($start->getHeaderLine('Location'), PHP_URL_QUERY), $query);

        return (string) ($query['state'] ?? '');
    }

    private function queueTokens(int $mlUserId): void
    {
        $this->ml->queueJson(200, [
            'access_token' => FakeMercadoLivre::TOKEN,
            'token_type' => 'bearer',
            'expires_in' => 21600,
            'scope' => 'offline_access read write',
            'user_id' => $mlUserId,
            'refresh_token' => self::REFRESH,
        ]);
    }

    /** @return list<int> */
    private function credentialOwners(): array
    {
        return array_map('intval', $this->db->query('SELECT installation_id FROM ml_credentials ORDER BY installation_id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function rows(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
