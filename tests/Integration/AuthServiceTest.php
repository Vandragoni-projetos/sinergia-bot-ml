<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use Monolog\Handler\TestHandler;
use Sinergia\Application\Auth\AuthService;
use Sinergia\Application\Auth\LoginResult;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\LoginAttemptRepository;
use Sinergia\Infrastructure\Persistence\SessionRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Shared\Clock\FrozenClock;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;

/** Login, sessão, limite de tentativas e isolamento entre contas, contra MariaDB real. */
final class AuthServiceTest extends DatabaseTestCase
{
    private const string PASSWORD_A = 'senha-da-conta-a-123';
    private const string PASSWORD_B = 'senha-da-conta-b-456';

    private \PDO $db;
    private Installation $accountA;
    private Installation $accountB;
    private int $userA;
    private int $userB;
    private UserRepository $users;
    private FrozenClock $clock;
    private TestHandler $logs;
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->freshSchema();
        $installations = new InstallationRepository($this->db);
        $this->accountA = $installations->ensure('conta-a', 'Loja A', 'MLB');
        $this->accountB = $installations->ensure('conta-b', 'Loja B', 'MLB');

        $hasher = new PasswordHasher();
        $this->users = new UserRepository($this->db);
        $this->userA = $this->users->create($this->accountA->id, 'ana@loja-a.test', 'Ana', $hasher->hash(new SensitiveValue(self::PASSWORD_A)));
        $this->userB = $this->users->create($this->accountB->id, 'bia@loja-b.test', 'Bia', $hasher->hash(new SensitiveValue(self::PASSWORD_B)));

        $this->clock = new FrozenClock('2026-09-25T12:00:00Z');
        $this->logs = new TestHandler();
        $this->auth = new AuthService(
            $this->users,
            new SessionRepository($this->db),
            new LoginAttemptRepository($this->db),
            $hasher,
            $this->clock,
            LoggerFactory::create('local', $this->logs),
        );
    }

    public function testSuccessfulLoginCreatesHashedSessionForTheUsersOwnAccount(): void
    {
        $result = $this->auth->login('  ANA@Loja-A.test ', new SensitiveValue(self::PASSWORD_A), '200.1.1.1', 'Teste/1.0');

        self::assertTrue($result->succeeded());
        self::assertSame($this->accountA->id->value, $result->context?->installationId->value);
        self::assertSame($this->userA, $result->context?->userId);
        self::assertSame('Loja A', $result->context?->installationName);

        $token = $result->sessionToken?->reveal() ?? '';
        $stored = $this->db->query('SELECT id, installation_id, user_id FROM user_sessions')->fetchAll();
        self::assertCount(1, $stored);
        self::assertSame(hash('sha256', $token), $stored[0]['id'], 'Banco guarda só o hash do token');
        self::assertNotSame($token, $stored[0]['id']);
        self::assertNotNull($this->db->query("SELECT last_login_at FROM users WHERE id = {$this->userA}")->fetchColumn());
        $this->assertNothingSensitiveInLogs($token);
    }

    public function testWrongPasswordAndUnknownEmailGiveTheSameAnswer(): void
    {
        $wrong = $this->auth->login('ana@loja-a.test', new SensitiveValue('senha-errada-aqui'), '200.1.1.1', null);
        $unknown = $this->auth->login('ninguem@loja.test', new SensitiveValue('qualquer-senha-123'), '200.1.1.1', null);

        self::assertSame(LoginResult::INVALID, $wrong->outcome);
        self::assertSame(LoginResult::INVALID, $unknown->outcome);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn());
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0')->fetchColumn());
        $this->assertNothingSensitiveInLogs('senha-errada-aqui');
    }

    public function testEmailIsBlockedAfterFiveFailuresEvenWithTheRightPassword(): void
    {
        for ($i = 0; $i < AuthService::MAX_FAILURES_PER_EMAIL; $i++) {
            $this->auth->login('ana@loja-a.test', new SensitiveValue('errada-' . $i . '-xxxxxx'), '200.1.1.' . $i, null);
        }

        $blocked = $this->auth->login('ana@loja-a.test', new SensitiveValue(self::PASSWORD_A), '200.9.9.9', null);
        self::assertSame(LoginResult::THROTTLED, $blocked->outcome);

        $this->clock->advance('PT16M');
        self::assertTrue($this->auth->login('ana@loja-a.test', new SensitiveValue(self::PASSWORD_A), '200.9.9.9', null)->succeeded());
    }

    public function testIpIsBlockedAfterTwentyFailuresAcrossEmails(): void
    {
        for ($i = 0; $i < AuthService::MAX_FAILURES_PER_IP; $i++) {
            $this->auth->login('robo' . $i . '@x.test', new SensitiveValue('qualquer-senha-123'), '200.7.7.7', null);
        }

        self::assertSame(LoginResult::THROTTLED, $this->auth->login('ana@loja-a.test', new SensitiveValue(self::PASSWORD_A), '200.7.7.7', null)->outcome);
        self::assertTrue($this->auth->login('ana@loja-a.test', new SensitiveValue(self::PASSWORD_A), '200.7.7.8', null)->succeeded(), 'Outro IP segue normal');
    }

    public function testSessionResolvesUntilIdleTimeoutAndLogout(): void
    {
        $token = $this->auth->login('ana@loja-a.test', new SensitiveValue(self::PASSWORD_A), '200.1.1.1', null)->sessionToken?->reveal();

        $this->clock->advance('PT11H');
        self::assertSame($this->userA, $this->auth->resolve($token)?->userId, 'Ainda válida e renovada pelo uso');

        $this->clock->advance('PT11H');
        self::assertNotNull($this->auth->resolve($token), 'Uso renovou a validade de ociosidade');

        $this->clock->advance('PT13H');
        self::assertNull($this->auth->resolve($token), 'Expira após 12 h sem uso');

        $fresh = $this->auth->login('ana@loja-a.test', new SensitiveValue(self::PASSWORD_A), '200.1.1.1', null)->sessionToken?->reveal();
        self::assertNotNull($this->auth->resolve($fresh));
        $this->auth->logout($fresh);
        self::assertNull($this->auth->resolve($fresh), 'Logout revoga no servidor');
    }

    public function testSessionNeverOutlivesSevenDays(): void
    {
        $token = $this->auth->login('ana@loja-a.test', new SensitiveValue(self::PASSWORD_A), '200.1.1.1', null)->sessionToken?->reveal();
        for ($day = 0; $day < 8; $day++) { // 8 × 20 h = 160 h de uso contínuo
            $this->clock->advance('PT10H');
            $this->auth->resolve($token);
            $this->clock->advance('PT10H');
            $this->auth->resolve($token);
        }
        $this->clock->advance('PT10H');

        self::assertNull($this->auth->resolve($token), 'Limite absoluto de 7 dias, mesmo com uso contínuo');
    }

    public function testSessionSurvivesANewProcess(): void
    {
        $token = $this->auth->login('ana@loja-a.test', new SensitiveValue(self::PASSWORD_A), '200.1.1.1', null)->sessionToken?->reveal();

        // "Novo processo" (deploy/restart): serviço e repositórios novos, mesma base.
        $afterRestart = new AuthService(
            new UserRepository($this->db),
            new SessionRepository($this->db),
            new LoginAttemptRepository($this->db),
            new PasswordHasher(),
            $this->clock,
            LoggerFactory::create('local', new TestHandler()),
        );

        self::assertSame($this->userA, $afterRestart->resolve($token)?->userId);
    }

    public function testTenantsAreIsolated(): void
    {
        $tokenA = $this->auth->login('ana@loja-a.test', new SensitiveValue(self::PASSWORD_A), '200.1.1.1', null)->sessionToken?->reveal();
        $tokenB = $this->auth->login('bia@loja-b.test', new SensitiveValue(self::PASSWORD_B), '200.1.1.2', null)->sessionToken?->reveal();

        self::assertTrue($this->auth->resolve($tokenA)?->installationId->equals($this->accountA->id));
        self::assertTrue($this->auth->resolve($tokenB)?->installationId->equals($this->accountB->id));

        // Busca por usuário é sempre escopada pela conta.
        self::assertNull($this->users->findInInstallation($this->accountB->id, $this->userA));
        self::assertNotNull($this->users->findInInstallation($this->accountA->id, $this->userA));

        // A senha de uma conta não abre a outra.
        self::assertFalse($this->auth->login('bia@loja-b.test', new SensitiveValue(self::PASSWORD_A), '200.1.1.3', null)->succeeded());

        // O banco recusa sessão que misture conta e usuário de outra conta (FK composta).
        $this->expectException(\PDOException::class);
        (new SessionRepository($this->db))->create(
            str_repeat('a', 64), $this->accountB->id, $this->userA, $this->clock->now(), $this->clock->now()->modify('+1 hour'), null, null,
        );
    }

    public function testDisabledUserOrAccountCannotSignInAndLosesExistingSession(): void
    {
        $token = $this->auth->login('ana@loja-a.test', new SensitiveValue(self::PASSWORD_A), '200.1.1.1', null)->sessionToken?->reveal();

        $this->db->exec("UPDATE users SET status = 'disabled' WHERE id = {$this->userA}");
        self::assertNull($this->auth->resolve($token));
        self::assertFalse($this->auth->login('ana@loja-a.test', new SensitiveValue(self::PASSWORD_A), '200.1.1.1', null)->succeeded());

        $this->db->exec("UPDATE users SET status = 'active' WHERE id = {$this->userA}");
        $this->db->exec("UPDATE installations SET status = 'suspended' WHERE id = {$this->accountA->id->value}");
        self::assertNull($this->auth->resolve($token), 'Conta suspensa derruba a sessão');
    }

    public function testMalformedTokensAreRejectedWithoutQuery(): void
    {
        self::assertNull($this->auth->resolve(null));
        self::assertNull($this->auth->resolve(''));
        self::assertNull($this->auth->resolve("' OR 1=1 --"));
        self::assertNull($this->auth->resolve(str_repeat('A', 43)), 'Formato válido, mas inexistente');
    }

    private function assertNothingSensitiveInLogs(string ...$secrets): void
    {
        $text = '';
        foreach ($this->logs->getRecords() as $record) {
            $text .= json_encode($record->toArray(), JSON_UNESCAPED_SLASHES) . "\n";
        }
        foreach ([...$secrets, self::PASSWORD_A, self::PASSWORD_B, 'ana@loja-a.test'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }
}
