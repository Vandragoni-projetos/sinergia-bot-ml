<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use Sinergia\Application\Port\MercadoLivre\TokenSet;
use Sinergia\Cli\Command\OAuthRefreshCommand;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthClient;
use Sinergia\Integration\MercadoLivre\OAuth\StoredTokenProvider;
use Sinergia\Shared\Clock\FrozenClock;
use Sinergia\Shared\Config\MercadoLivreConfig;
use Sinergia\Shared\Config\MercadoLivreOAuthConfig;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Tests\Support\ArrayContainer;
use Sinergia\Tests\Support\FakeMercadoLivre;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** ml:oauth:refresh contra MariaDB real, HTTP do Mercado Livre falso. */
final class OAuthRefreshCommandTest extends DatabaseTestCase
{
    private const string OLD_ACCESS = 'APP_USR-old-access-token-0001';
    private const string OLD_REFRESH = 'TG-old-refresh-token-0001';
    private const string NEW_ACCESS = 'APP_USR-new-access-token-0002';
    private const string NEW_REFRESH = 'TG-new-refresh-token-0002';
    private const string SECRET = 'fake-client-secret-for-tests-only';

    private \PDO $db;
    private string $appKeyBase64;
    private SecretBox $box;
    private Installation $installation;
    private MlCredentialRepository $repo;
    private FakeMercadoLivre $ml;
    private FrozenClock $clock;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->freshSchema();
        $this->installation = (new InstallationRepository($this->db))->ensure('default', 'Local', 'MLB');
        $this->appKeyBase64 = SecretBox::generateKeyBase64();
        $this->box = new SecretBox(new SensitiveValue((string) base64_decode($this->appKeyBase64, true)));
        $this->repo = new MlCredentialRepository($this->db, $this->box);
        $this->ml = new FakeMercadoLivre(withToken: false);
        $this->clock = new FrozenClock('2026-09-24T12:00:00Z');

        $oauth = new OAuthClient(
            $this->ml->client,
            new MercadoLivreConfig('MLB', 'https://api.mercadolibre.com', 'https://auth.mercadolivre.com.br', 5, 'test'),
            new MercadoLivreOAuthConfig('1234567890123456', new SensitiveValue(self::SECRET), 'https://app.example.test/oauth/mercadolivre/callback', true),
        );
        $this->tester = new CommandTester(new OAuthRefreshCommand(new ArrayContainer([
            Installation::class => $this->installation,
            MlCredentialRepository::class => $this->repo,
            StoredTokenProvider::class => new StoredTokenProvider($this->installation->id, $this->repo, $oauth, $this->clock),
        ])));
    }

    public function testDoesNothingWhileAccessTokenIsValid(): void
    {
        $this->connect(refresh: true);
        $this->clock->advance('PT30M');

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));

        self::assertStringContainsString('Renovação não necessária', $this->tester->getDisplay());
        self::assertStringContainsString('access_expira_em=2026-09-24 13:00:00 UTC', $this->tester->getDisplay());
        self::assertSame([], $this->ml->history);
        self::assertSame(1, $this->version());
        $this->assertSafeOutput();
    }

    public function testRefreshesInsideRenewalWindow(): void
    {
        $this->connect(refresh: true);
        $this->clock->advance('PT56M');
        $this->queueRefresh();

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Token renovado.', $display);
        self::assertStringContainsString('status=connected', $display);
        self::assertStringContainsString('access_expira_em=2026-09-24 18:56:00 UTC', $display);
        self::assertStringContainsString('refresh_token=presente (cifrado)', $display);
        self::assertCount(1, $this->ml->history);
        self::assertSame(2, $this->version());
        self::assertSame(self::NEW_REFRESH, $this->repo->find($this->installation->id)?->refreshToken?->reveal());
        $this->assertSafeOutput();
    }

    public function testForceRefreshesValidToken(): void
    {
        $this->connect(refresh: true);
        $this->clock->advance('PT10M');
        $this->queueRefresh();

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--force' => true]));

        self::assertStringContainsString('Token renovado.', $this->tester->getDisplay());
        parse_str((string) $this->ml->lastRequest()->getBody(), $form);
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertSame(self::OLD_REFRESH, $form['refresh_token']);
        self::assertSame(2, $this->version());
        $this->assertSafeOutput();
    }

    public function testRefusesExpiredOrRevokedCredentialWithoutCallingMercadoLivre(): void
    {
        $this->connect(refresh: true);

        foreach (['expired', 'revoked'] as $status) {
            $this->repo->markStatus($this->installation->id, $status);

            self::assertSame(Command::FAILURE, $this->tester->execute(['--force' => true]));

            self::assertStringContainsString('credential_not_connected', $this->tester->getDisplay());
            self::assertStringContainsString('status=' . $status, $this->tester->getDisplay());
            self::assertSame([], $this->ml->history);
            self::assertSame(1, $this->version());
            $this->assertSafeOutput();
        }
    }

    public function testFailsCleanlyWhenNotConnected(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute([]));

        self::assertStringContainsString('oauth_not_connected', $this->tester->getDisplay());
        self::assertStringContainsString('Não conectado', $this->tester->getDisplay());
        self::assertSame([], $this->ml->history);
    }

    public function testInvalidGrantMarksExpiredAndHidesSecrets(): void
    {
        $this->connect(refresh: true);
        $this->ml->queueJson(400, ['error' => 'invalid_grant', 'message' => 'refresh ' . self::OLD_REFRESH . ' invalid', 'status' => 400]);

        self::assertSame(Command::FAILURE, $this->tester->execute(['--force' => true]));

        self::assertStringContainsString('invalid_grant', $this->tester->getDisplay());
        self::assertStringContainsString('status=expired', $this->tester->getDisplay());
        self::assertSame(1, $this->version());
        $this->assertSafeOutput();
    }

    public function testForceWithoutRefreshTokenFailsWithoutExpiringValidAccess(): void
    {
        $this->connect(refresh: false);

        self::assertSame(Command::FAILURE, $this->tester->execute(['--force' => true]));

        self::assertStringContainsString('refresh_unavailable', $this->tester->getDisplay());
        self::assertStringContainsString('status=connected', $this->tester->getDisplay(), 'Access ainda válido não é marcado expired');
        self::assertStringContainsString('refresh_token=ausente', $this->tester->getDisplay());
        self::assertSame([], $this->ml->history);
        $this->assertSafeOutput();
    }

    private function connect(bool $refresh): void
    {
        $this->repo->save($this->installation->id, '1234567890123456', new TokenSet(
            new SensitiveValue(self::OLD_ACCESS),
            $refresh ? new SensitiveValue(self::OLD_REFRESH) : null,
            3600,
            'offline_access read write',
            42,
            'bearer',
        ), $this->clock->now());
    }

    private function queueRefresh(): void
    {
        $this->ml->queueJson(200, [
            'access_token' => self::NEW_ACCESS,
            'token_type' => 'bearer',
            'expires_in' => 21600,
            'scope' => 'offline_access read write',
            'user_id' => 42,
            'refresh_token' => self::NEW_REFRESH,
        ]);
    }

    private function version(): int
    {
        return (int) $this->db->query('SELECT version FROM ml_credentials')->fetchColumn();
    }

    private function assertSafeOutput(): void
    {
        $exposed = $this->tester->getDisplay() . "\n" . $this->ml->logText();
        foreach ([self::OLD_ACCESS, self::OLD_REFRESH, self::NEW_ACCESS, self::NEW_REFRESH, self::SECRET, $this->appKeyBase64] as $secret) {
            self::assertStringNotContainsString($secret, $exposed);
        }
    }
}
