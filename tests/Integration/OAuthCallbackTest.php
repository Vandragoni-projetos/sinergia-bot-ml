<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use Monolog\Handler\TestHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sinergia\Application\OAuth\CompleteMercadoLivreAuthorization;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Infrastructure\Persistence\OAuthStateRepository;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthClient;
use Sinergia\Shared\Clock\FrozenClock;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\MercadoLivreConfig;
use Sinergia\Shared\Config\MercadoLivreOAuthConfig;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Tests\Support\ArrayContainer;
use Sinergia\Tests\Support\FakeMercadoLivre;
use Sinergia\Web\HttpApp;

/**
 * GET /oauth/mercadolivre/callback de ponta a ponta: MariaDB de teste real, HTTP do Mercado Livre falso.
 */
final class OAuthCallbackTest extends DatabaseTestCase
{
    private const string CODE = 'TG-fake-authorization-code-0001';
    private const string STATE = 'fake-state-value-0001';
    private const string VERIFIER = 'fake-pkce-verifier-with-enough-length-0123456789-abcdefghij';
    private const string SECRET = 'fake-client-secret-for-tests-only';
    private const string REFRESH = 'TG-fake-refresh-token-0001';

    private \PDO $db;
    private Installation $installation;
    private OAuthStateRepository $states;
    private MlCredentialRepository $credentials;
    private FakeMercadoLivre $ml;
    private FrozenClock $clock;
    private TestHandler $logs;
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->freshSchema();
        $this->installation = (new InstallationRepository($this->db))->ensure('default', 'Local', 'MLB');
        $box = new SecretBox(new SensitiveValue((string) base64_decode(SecretBox::generateKeyBase64(), true)));
        $this->states = new OAuthStateRepository($this->db, $box);
        $this->credentials = new MlCredentialRepository($this->db, $box);
        $this->ml = new FakeMercadoLivre(withToken: false);
        $this->clock = new FrozenClock('2026-09-24T12:00:00Z');
        $this->logs = new TestHandler();
        $logger = LoggerFactory::create('local', $this->logs);

        $oauth = new OAuthClient(
            $this->ml->client,
            new MercadoLivreConfig('MLB', 'https://api.mercadolibre.com', 'https://auth.mercadolivre.com.br', 5, 'test'),
            new MercadoLivreOAuthConfig('1234567890123456', new SensitiveValue(self::SECRET), 'https://app.example.test/oauth/mercadolivre/callback', true),
        );
        $this->app = HttpApp::create(new ArrayContainer([
            Config::class => Config::fromArray(['APP_ENV' => 'test']),
            LoggerInterface::class => $logger,
            Installation::class => $this->installation,
            CompleteMercadoLivreAuthorization::class => new CompleteMercadoLivreAuthorization(
                $this->states, $oauth, $this->credentials, $this->clock, $logger,
            ),
        ]));

        $this->states->create(
            $this->installation->id,
            new SensitiveValue(self::STATE),
            new SensitiveValue(self::VERIFIER),
            $this->clock->now()->modify('+10 minutes'),
        );
    }

    public function testValidCallbackStoresEncryptedTokensWithLongScopes(): void
    {
        $scopes = 'offline_access read write ' . implode(' ', array_map(
            static fn (int $i): string => sprintf('urn:ml:all:scope-%03d:/read-write', $i),
            range(1, 60),
        ));
        $this->queueTokens($scopes);

        $response = $this->hitCallback(['code' => self::CODE, 'state' => self::STATE]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Mercado Livre conectado com sucesso.', (string) $response->getBody());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));

        // Troca oficial com o code recebido e o code_verifier PKCE guardado no start.
        parse_str((string) $this->ml->lastRequest()->getBody(), $form);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame(self::CODE, $form['code']);
        self::assertSame(self::VERIFIER, $form['code_verifier']);

        // Tokens cifrados em repouso; scopes longos íntegros; state consumido.
        $raw = $this->db->query('SELECT access_token_enc, refresh_token_enc FROM ml_credentials')->fetchAll();
        self::assertCount(1, $raw);
        self::assertStringNotContainsString(FakeMercadoLivre::TOKEN, (string) $raw[0]['access_token_enc']);
        self::assertStringNotContainsString(self::REFRESH, (string) $raw[0]['refresh_token_enc']);
        $stored = $this->credentials->find($this->installation->id);
        self::assertSame(FakeMercadoLivre::TOKEN, $stored?->accessToken->reveal());
        self::assertSame($scopes, $stored?->scopes);
        self::assertSame(0, $this->rows('ml_oauth_states'));

        $this->assertNothingSensitiveLeaked($response);
    }

    public function testUnknownStateIsRejectedWithoutCallingMercadoLivre(): void
    {
        $response = $this->hitCallback(['code' => self::CODE, 'state' => 'fake-state-that-was-never-issued']);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('state_unknown', (string) $response->getBody());
        self::assertSame([], $this->ml->history, 'Nenhuma chamada ao Mercado Livre');
        self::assertSame(0, $this->rows('ml_credentials'));
        self::assertSame(1, $this->rows('ml_oauth_states'), 'State legítimo continua pendente');
        $this->assertNothingSensitiveLeaked($response);
    }

    public function testExpiredStateIsRejectedAndConsumed(): void
    {
        $this->clock->advance('PT11M');

        $response = $this->hitCallback(['code' => self::CODE, 'state' => self::STATE]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('state_expired', (string) $response->getBody());
        self::assertSame([], $this->ml->history);
        self::assertSame(0, $this->rows('ml_credentials'));
        self::assertSame(0, $this->rows('ml_oauth_states'));
        $this->assertNothingSensitiveLeaked($response);
    }

    public function testStateCannotBeReused(): void
    {
        $this->queueTokens('offline_access read');
        self::assertSame(200, $this->hitCallback(['code' => self::CODE, 'state' => self::STATE])->getStatusCode());

        $second = $this->hitCallback(['code' => self::CODE, 'state' => self::STATE]);

        self::assertSame(400, $second->getStatusCode());
        self::assertStringContainsString('state_unknown', (string) $second->getBody());
        self::assertCount(1, $this->ml->history, 'O code não é trocado duas vezes');
        $this->assertNothingSensitiveLeaked($second);
    }

    public function testMissingCodeIsRejectedWithoutConsumingState(): void
    {
        $response = $this->hitCallback(['state' => self::STATE]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('missing_parameters', (string) $response->getBody());
        self::assertSame(1, $this->rows('ml_oauth_states'));
        self::assertSame([], $this->ml->history);
        $this->assertNothingSensitiveLeaked($response);
    }

    public function testMissingStateIsRejected(): void
    {
        $response = $this->hitCallback(['code' => self::CODE]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('missing_parameters', (string) $response->getBody());
        self::assertSame(1, $this->rows('ml_oauth_states'));
        self::assertSame([], $this->ml->history);
        $this->assertNothingSensitiveLeaked($response);
    }

    public function testAuthorizationDeniedByMercadoLivre(): void
    {
        $response = $this->hitCallback(['error' => 'access_denied', 'state' => self::STATE]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('authorization_denied', (string) $response->getBody());
        self::assertSame([], $this->ml->history);
        self::assertSame(0, $this->rows('ml_credentials'));
        $this->assertNothingSensitiveLeaked($response);
    }

    public function testFailedTokenExchangeStoresNothing(): void
    {
        $this->ml->queueJson(400, [
            'error' => 'invalid_grant',
            'message' => 'code ' . self::CODE . ' invalid',
            'status' => 400,
        ]);

        $response = $this->hitCallback(['code' => self::CODE, 'state' => self::STATE]);

        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('token_exchange_failed', (string) $response->getBody());
        self::assertSame(0, $this->rows('ml_credentials'));
        self::assertSame(0, $this->rows('ml_oauth_states'), 'State é de uso único mesmo quando a troca falha');
        $this->assertNothingSensitiveLeaked($response);
    }

    /** @param array<string, string> $query */
    private function hitCallback(array $query): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/oauth/mercadolivre/callback?' . http_build_query($query))
            ->withQueryParams($query);

        return $this->app->handle($request);
    }

    private function queueTokens(string $scopes): void
    {
        $this->ml->queueJson(200, [
            'access_token' => FakeMercadoLivre::TOKEN,
            'token_type' => 'bearer',
            'expires_in' => 21600,
            'scope' => $scopes,
            'user_id' => 1234567,
            'refresh_token' => self::REFRESH,
        ]);
    }

    private function rows(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function assertNothingSensitiveLeaked(ResponseInterface $response): void
    {
        $exposed = (string) $response->getBody() . "\n" . json_encode($response->getHeaders()) . "\n" . $this->ml->logText();
        foreach ($this->logs->getRecords() as $record) {
            $exposed .= json_encode($record->toArray(), JSON_UNESCAPED_SLASHES) . "\n";
        }

        foreach ([self::CODE, self::STATE, self::VERIFIER, self::SECRET, self::REFRESH, FakeMercadoLivre::TOKEN] as $secret) {
            self::assertStringNotContainsString($secret, $exposed);
        }
    }
}
