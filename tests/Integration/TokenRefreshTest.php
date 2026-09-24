<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use Sinergia\Application\Port\MercadoLivre\CredentialStore;
use Sinergia\Application\Port\MercadoLivre\StoredCredential;
use Sinergia\Application\Port\MercadoLivre\TokenSet;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Database\ConnectionFactory;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthClient;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthException;
use Sinergia\Integration\MercadoLivre\OAuth\StoredTokenProvider;
use Sinergia\Shared\Clock\FrozenClock;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\MercadoLivreConfig;
use Sinergia\Shared\Config\MercadoLivreOAuthConfig;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Tests\Support\FakeMercadoLivre;

/**
 * Renovação do access token (StoredTokenProvider + MlCredentialRepository) contra MariaDB real:
 * transação + SELECT ... FOR UPDATE como em produção. HTTP do Mercado Livre é falso.
 */
final class TokenRefreshTest extends DatabaseTestCase
{
    private const string OLD_ACCESS = 'APP_USR-old-access-token-0001';
    private const string OLD_REFRESH = 'TG-old-refresh-token-0001';
    private const string NEW_ACCESS = 'APP_USR-new-access-token-0002';
    private const string NEW_REFRESH = 'TG-new-refresh-token-0002';
    private const string SECRET = 'fake-client-secret-for-tests-only';

    private \PDO $db;
    private string $appKeyBase64;
    private SecretBox $box;
    private InstallationId $inst;
    private MlCredentialRepository $repo;
    private FakeMercadoLivre $ml;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->freshSchema();
        $this->db->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        $this->inst = (new InstallationRepository($this->db))->ensure('default', 'Local', 'MLB')->id;
        $this->appKeyBase64 = SecretBox::generateKeyBase64();
        $this->box = new SecretBox(new SensitiveValue((string) base64_decode($this->appKeyBase64, true)));
        $this->repo = new MlCredentialRepository($this->db, $this->box);
        $this->ml = new FakeMercadoLivre(withToken: false);
        $this->clock = new FrozenClock('2026-09-24T12:00:00Z');

        // Conexão inicial: access expira às 13:00 (3600 s), com refresh token.
        $this->repo->save($this->inst, '1234567890123456', new TokenSet(
            new SensitiveValue(self::OLD_ACCESS), new SensitiveValue(self::OLD_REFRESH), 3600, 'offline_access read write', 42, 'bearer',
        ), $this->clock->now());
    }

    public function testValidAccessTokenIsReusedWithoutRefresh(): void
    {
        $this->clock->advance('PT30M'); // faltam 30 min: fora da margem de 5 min

        $token = $this->provider()->accessToken();

        self::assertSame(self::OLD_ACCESS, $token->reveal());
        self::assertSame([], $this->ml->history, 'Nenhuma chamada ao /oauth/token');
        $row = $this->row();
        self::assertSame(1, (int) $row['version']);
        self::assertSame('2026-09-24 12:30:00.000', $row['last_api_call_at']);
    }

    public function testTokenCloseToExpiryIsRefreshedAndStoredEncrypted(): void
    {
        $this->clock->advance('PT56M'); // faltam 4 min: dentro da margem de 5 min
        $this->queueRefresh(withNewRefreshToken: true);

        $token = $this->provider()->accessToken();

        self::assertSame(self::NEW_ACCESS, $token->reveal());
        self::assertCount(1, $this->ml->history);
        parse_str((string) $this->ml->lastRequest()->getBody(), $form);
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertSame(self::OLD_REFRESH, $form['refresh_token']);

        $row = $this->row();
        self::assertStringNotContainsString(self::NEW_ACCESS, (string) $row['access_token_enc']);
        self::assertStringNotContainsString(self::NEW_REFRESH, (string) $row['refresh_token_enc']);
        self::assertSame(self::NEW_ACCESS, $this->box->decrypt((string) $row['access_token_enc'])->reveal());
        self::assertSame(self::NEW_REFRESH, $this->box->decrypt((string) $row['refresh_token_enc'])->reveal(), 'Novo refresh substitui o antigo');
        self::assertSame('2026-09-24 18:56:00.000', $row['access_expires_at'], 'refresh às 12:56 + 21600 s');
        self::assertSame('2026-09-24 12:56:00.000', $row['last_refresh_at']);
        self::assertSame('2026-09-24 12:56:00.000', $row['refresh_obtained_at']);
        self::assertSame(2, (int) $row['version']);
        self::assertSame('connected', $row['status']);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM ml_credentials')->fetchColumn());
        $this->assertNoSecretsIn($this->ml->logText());
    }

    public function testRefreshWithoutNewRefreshTokenKeepsThePreviousOne(): void
    {
        $this->clock->advance('PT56M');
        $this->queueRefresh(withNewRefreshToken: false);

        self::assertSame(self::NEW_ACCESS, $this->provider()->accessToken()->reveal());

        $row = $this->row();
        self::assertSame(self::OLD_REFRESH, $this->box->decrypt((string) $row['refresh_token_enc'])->reveal());
        self::assertSame('2026-09-24 12:00:00.000', $row['refresh_obtained_at'], 'Data do refresh token antigo preservada');
        self::assertSame('2026-09-24 12:56:00.000', $row['last_refresh_at']);
        self::assertSame(2, (int) $row['version']);
    }

    public function testInvalidGrantMarksCredentialExpired(): void
    {
        $this->clock->advance('PT56M');
        $this->ml->queueJson(400, [
            'error' => 'invalid_grant',
            'message' => 'refresh ' . self::OLD_REFRESH . ' invalid',
            'status' => 400,
        ]);

        try {
            $this->provider()->accessToken();
            self::fail('Deveria falhar.');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->errorCode);
            $this->assertNoSecretsIn($e->getMessage());
        }

        $row = $this->row();
        self::assertSame('expired', $row['status']);
        self::assertSame(1, (int) $row['version'], 'Tokens não foram sobrescritos');
        self::assertSame(self::OLD_REFRESH, $this->box->decrypt((string) $row['refresh_token_enc'])->reveal());
        $this->assertNoSecretsIn($this->ml->logText());
    }

    public function testExpiredTokenWithoutRefreshTokenFailsWithRefreshUnavailable(): void
    {
        $this->db->exec('UPDATE ml_credentials SET refresh_token_enc = NULL');
        $this->clock->advance('PT2H');

        try {
            $this->provider()->accessToken();
            self::fail('Deveria falhar.');
        } catch (OAuthException $e) {
            self::assertSame('refresh_unavailable', $e->errorCode);
            $this->assertNoSecretsIn($e->getMessage());
        }

        self::assertSame([], $this->ml->history, 'Sem refresh token não há chamada ao Mercado Livre');
        self::assertSame('expired', $this->row()['status']);
    }

    public function testRefreshWaitsForRowLockHeldByAnotherConnection(): void
    {
        $this->clock->advance('PT56M');
        $this->queueRefresh(withNewRefreshToken: true);
        $other = $this->secondConnection();
        $other->beginTransaction();
        $other->query('SELECT id FROM ml_credentials WHERE installation_id = ' . $this->inst->value . ' FOR UPDATE')->fetchAll();
        $this->db->exec('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->provider()->accessToken();
            self::fail('Deveria esperar o lock do outro processo.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('1205', $e->getMessage(), 'Lock wait timeout');
        } finally {
            $other->rollBack();
            $this->db->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }

        self::assertSame([], $this->ml->history, 'Sem o lock, o refresh token não é usado');
        self::assertSame(1, (int) $this->row()['version']);
    }

    public function testDoubleCheckAfterLockPreventsSecondRefresh(): void
    {
        // Os dois "processos" leem a credencial prestes a expirar; o segundo renova primeiro
        // (em outra conexão) enquanto o primeiro espera o lock. Só pode haver UM refresh.
        $this->clock->advance('PT56M');
        $this->queueRefresh(withNewRefreshToken: true);
        $otherProcess = new StoredTokenProvider(
            $this->inst,
            new MlCredentialRepository($this->secondConnection(), $this->box),
            $this->oauth(),
            $this->clock,
        );
        $racingStore = new class ($this->repo, static fn () => $otherProcess->accessToken()) implements CredentialStore {
            private bool $raced = false;

            /** @param \Closure(): SensitiveValue $race */
            public function __construct(private readonly MlCredentialRepository $inner, private readonly \Closure $race)
            {
            }

            public function find(InstallationId $installation): ?StoredCredential
            {
                return $this->inner->find($installation);
            }

            public function save(InstallationId $installation, string $clientId, TokenSet $tokens, \DateTimeImmutable $now): void
            {
                $this->inner->save($installation, $clientId, $tokens, $now);
            }

            public function markStatus(InstallationId $installation, string $status): void
            {
                $this->inner->markStatus($installation, $status);
            }

            public function touchApiCall(InstallationId $installation, \DateTimeImmutable $now): void
            {
                $this->inner->touchApiCall($installation, $now);
            }

            public function withLockedCredential(InstallationId $installation, callable $work): mixed
            {
                if (!$this->raced) {
                    $this->raced = true;
                    ($this->race)(); // outro processo termina o refresh antes de liberarmos o lock
                }

                return $this->inner->withLockedCredential($installation, $work);
            }
        };

        $token = (new StoredTokenProvider($this->inst, $racingStore, $this->oauth(), $this->clock))->accessToken();

        self::assertSame(self::NEW_ACCESS, $token->reveal(), 'Usa o token que o outro processo gravou');
        self::assertCount(1, $this->ml->history, 'Um único POST /oauth/token');
        self::assertSame(2, (int) $this->row()['version']);
    }

    private function provider(): StoredTokenProvider
    {
        return new StoredTokenProvider($this->inst, $this->repo, $this->oauth(), $this->clock);
    }

    private function oauth(): OAuthClient
    {
        return new OAuthClient(
            $this->ml->client,
            new MercadoLivreConfig('MLB', 'https://api.mercadolibre.com', 'https://auth.mercadolivre.com.br', 5, 'test'),
            new MercadoLivreOAuthConfig('1234567890123456', new SensitiveValue(self::SECRET), 'https://app.example.test/oauth/mercadolivre/callback', true),
        );
    }

    private function queueRefresh(bool $withNewRefreshToken): void
    {
        $this->ml->queueJson(200, [
            'access_token' => self::NEW_ACCESS,
            'token_type' => 'bearer',
            'expires_in' => 21600,
            'scope' => 'offline_access read write',
            'user_id' => 42,
        ] + ($withNewRefreshToken ? ['refresh_token' => self::NEW_REFRESH] : []));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = $this->db->query(
            'SELECT access_token_enc, refresh_token_enc, access_expires_at, refresh_obtained_at, last_refresh_at,
                    last_api_call_at, status, version FROM ml_credentials WHERE installation_id = ' . $this->inst->value
        )->fetch();
        self::assertIsArray($row);

        return $row;
    }

    private function secondConnection(): \PDO
    {
        $env = array_merge($_ENV, getenv());
        $env['APP_ENV'] = 'test';

        return ConnectionFactory::create(Config::fromArray(array_filter($env, 'is_string'))->database('TEST_DB_'));
    }

    private function assertNoSecretsIn(string $text): void
    {
        foreach ([self::OLD_ACCESS, self::OLD_REFRESH, self::NEW_ACCESS, self::NEW_REFRESH, self::SECRET, $this->appKeyBase64] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }
}
