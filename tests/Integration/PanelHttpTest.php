<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Web\HttpApp;

/** Painel de ponta a ponta: container real do Kernel, MariaDB de teste, requisições HTTP simuladas. */
final class PanelHttpTest extends DatabaseTestCase
{
    private const string PASSWORD = 'senha-do-painel-123';

    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->freshSchema();
        $installations = new InstallationRepository($pdo);
        $a = $installations->ensure('conta-a', 'Loja A', 'MLB');
        $b = $installations->ensure('conta-b', 'Loja B', 'MLB');
        $hasher = new PasswordHasher();
        $users = new UserRepository($pdo);
        $users->create($a->id, 'ana@loja-a.test', 'Ana', $hasher->hash(new SensitiveValue(self::PASSWORD)));
        $users->create($b->id, 'bia@loja-b.test', 'Bia', $hasher->hash(new SensitiveValue(self::PASSWORD)));

        $env = array_filter(array_merge($_ENV, getenv()), 'is_string');
        $config = Config::fromArray([
            'APP_ENV' => 'test',
            'APP_KEY' => SecretBox::generateKeyBase64(),
            'DB_HOST' => (string) ($env['TEST_DB_HOST'] ?? ''),
            'DB_PORT' => (string) ($env['TEST_DB_PORT'] ?? '3306'),
            'DB_DATABASE' => (string) ($env['TEST_DB_DATABASE'] ?? ''),
            'DB_USERNAME' => (string) ($env['TEST_DB_USERNAME'] ?? ''),
            'DB_PASSWORD' => (string) ($env['TEST_DB_PASSWORD'] ?? ''),
        ]);
        $this->app = HttpApp::create(Kernel::container($config, dirname(__DIR__, 2)));
    }

    public function testPanelRequiresLogin(): void
    {
        foreach (['/', '/conexoes', '/nichos', '/destinos', '/fila'] as $path) {
            $response = $this->get($path);
            self::assertSame(302, $response->getStatusCode(), $path);
            self::assertSame('/entrar', $response->getHeaderLine('Location'), $path);
        }
    }

    public function testLoginPageHasCsrfAndStrictHeaders(): void
    {
        $response = $this->get('/entrar');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="_csrf"', $body);
        self::assertNotNull($this->cookie($response, 'sbm_pre'));
        self::assertStringContainsString("style-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringNotContainsString('script-src', $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testLoginWithoutValidCsrfIsRejected(): void
    {
        $page = $this->get('/entrar');
        $response = $this->post('/entrar', ['email' => 'ana@loja-a.test', 'password' => self::PASSWORD, '_csrf' => 'forjado'], ['sbm_pre' => $this->cookie($page, 'sbm_pre')]);

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($this->cookie($response, 'sbm_session'));
    }

    public function testWrongPasswordShowsGenericError(): void
    {
        $response = $this->login('ana@loja-a.test', 'senha-errada-000');
        $body = (string) $response->getBody();

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('E-mail ou senha incorretos.', $body);
        self::assertStringNotContainsString('senha-errada-000', $body, 'Senha nunca volta na página');
        self::assertNull($this->cookie($response, 'sbm_session'));
    }

    public function testFullSessionFlow(): void
    {
        $login = $this->login('ana@loja-a.test', self::PASSWORD);
        self::assertSame(302, $login->getStatusCode());
        self::assertSame('/', $login->getHeaderLine('Location'), 'A home decide: Primeiros passos ou Fila.');
        $session = $this->cookie($login, 'sbm_session');
        self::assertNotNull($session);
        $setCookie = implode("\n", $login->getHeader('Set-Cookie'));
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('SameSite=Lax', $setCookie);

        $cookies = ['sbm_session' => $session];
        foreach (['conexoes' => 'Conexões', 'nichos' => 'Nichos', 'destinos' => 'Destinos', 'fila' => 'Fila'] as $slug => $title) {
            $page = $this->get('/' . $slug, $cookies);
            $body = (string) $page->getBody();
            self::assertSame(200, $page->getStatusCode(), $slug);
            self::assertStringContainsString('<h1>' . $title . '</h1>', $body);
            self::assertStringContainsString('Loja A', $body);
            self::assertStringNotContainsString('Loja B', $body, 'Nunca mostra dados de outra conta');
            foreach (['/conexoes', '/nichos', '/destinos', '/fila'] as $link) {
                self::assertStringContainsString('href="' . $link . '"', $body);
            }
            self::assertStringContainsString('aria-current="page"', $body);
            self::assertStringNotContainsString($session, $body, 'Token da sessão nunca aparece na página');
        }

        self::assertSame('/comecar', $this->get('/', $cookies)->getHeaderLine('Location'), 'Conta nova (bot pausado) começa pelos Primeiros passos.');
        self::assertSame('/', $this->get('/entrar', $cookies)->getHeaderLine('Location'), 'Logado não vê a tela de entrada');

        // Logout exige CSRF da sessão.
        self::assertSame(400, $this->post('/sair', ['_csrf' => 'forjado'], $cookies)->getStatusCode());
        self::assertSame(200, $this->get('/fila', $cookies)->getStatusCode(), 'CSRF inválido não desloga');

        preg_match('/name="_csrf" value="([^"]+)"/', (string) $this->get('/fila', $cookies)->getBody(), $m);
        $logout = $this->post('/sair', ['_csrf' => $m[1] ?? ''], $cookies);
        self::assertSame(302, $logout->getStatusCode());
        self::assertSame('/entrar', $logout->getHeaderLine('Location'));
        self::assertStringContainsString('Max-Age=0', implode("\n", $logout->getHeader('Set-Cookie')));

        $after = $this->get('/fila', $cookies);
        self::assertSame(302, $after->getStatusCode(), 'Sessão revogada no servidor');
        self::assertSame('/entrar', $after->getHeaderLine('Location'));
    }

    public function testEachAccountSeesOnlyItself(): void
    {
        $sessionA = $this->cookie($this->login('ana@loja-a.test', self::PASSWORD), 'sbm_session');
        $sessionB = $this->cookie($this->login('bia@loja-b.test', self::PASSWORD), 'sbm_session');

        $pageA = (string) $this->get('/fila', ['sbm_session' => $sessionA])->getBody();
        $pageB = (string) $this->get('/fila', ['sbm_session' => $sessionB])->getBody();

        self::assertStringContainsString('Loja A', $pageA);
        self::assertStringNotContainsString('Loja B', $pageA);
        self::assertStringContainsString('Loja B', $pageB);
        self::assertStringNotContainsString('Loja A', $pageB);
    }

    public function testForgedSessionCookieIsRejectedAndCleared(): void
    {
        $response = $this->get('/fila', ['sbm_session' => str_repeat('A', 43)]);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('sbm_session=;', implode("\n", $response->getHeader('Set-Cookie')));
    }

    public function testHealthStillWorks(): void
    {
        self::assertSame(200, $this->get('/health')->getStatusCode());
    }

    private function login(string $email, string $password): ResponseInterface
    {
        $page = $this->get('/entrar');
        preg_match('/name="_csrf" value="([^"]+)"/', (string) $page->getBody(), $m);

        return $this->post('/entrar', ['email' => $email, 'password' => $password, '_csrf' => $m[1] ?? ''], ['sbm_pre' => $this->cookie($page, 'sbm_pre')]);
    }

    /** @param array<string, ?string> $cookies */
    private function get(string $path, array $cookies = []): ResponseInterface
    {
        return $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path, ['REMOTE_ADDR' => '200.10.10.10'])
                ->withCookieParams(array_filter($cookies, 'is_string'))
        );
    }

    /**
     * @param array<string, string> $body
     * @param array<string, ?string> $cookies
     */
    private function post(string $path, array $body, array $cookies = []): ResponseInterface
    {
        return $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', $path, ['REMOTE_ADDR' => '200.10.10.10'])
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withCookieParams(array_filter($cookies, 'is_string'))
                ->withParsedBody($body)
        );
    }

    private function cookie(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (preg_match('/^' . preg_quote($name, '/') . '=([^;]*)/', $header, $m) === 1 && $m[1] !== '') {
                return rawurldecode($m[1]);
            }
        }

        return null;
    }
}
