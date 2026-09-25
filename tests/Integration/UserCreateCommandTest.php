<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Cli\Command\UserCreateCommand;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UserCreateCommandTest extends DatabaseTestCase
{
    private \PDO $db;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->freshSchema();
        (new InstallationRepository($this->db))->ensure('conta-a', 'Loja A', 'MLB');
        $this->tester = new CommandTester(new UserCreateCommand(new ArrayContainer([
            InstallationRepository::class => new InstallationRepository($this->db),
            UserRepository::class => new UserRepository($this->db),
            PasswordHasher::class => new PasswordHasher(),
        ])));
    }

    public function testCreatesUserInTheNamedAccountWithHashedGeneratedPassword(): void
    {
        $code = $this->tester->execute(['email' => 'Ana@Loja-A.test', '--name' => 'Ana', '--installation' => 'conta-a', '--generate-password' => true]);

        self::assertSame(Command::SUCCESS, $code);
        preg_match('/Senha gerada .*: (\S+)/', $this->tester->getDisplay(), $m);
        $password = $m[1] ?? '';
        self::assertNotSame('', $password);

        $row = $this->db->query("SELECT u.email, u.password_hash, i.slug FROM users u JOIN installations i ON i.id = u.installation_id")->fetch();
        self::assertSame('ana@loja-a.test', $row['email'], 'E-mail normalizado');
        self::assertSame('conta-a', $row['slug']);
        self::assertNotSame($password, $row['password_hash']);
        self::assertTrue((new PasswordHasher())->verify(new SensitiveValue($password), (string) $row['password_hash']));
    }

    public function testRejectsUnknownAccountDuplicateEmailAndMissingOptions(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'x@y.test', '--name' => 'X', '--installation' => 'nao-existe', '--generate-password' => true]));
        self::assertSame(Command::INVALID, $this->tester->execute(['email' => 'x@y.test', '--name' => 'X', '--generate-password' => true]));
        self::assertSame(Command::INVALID, $this->tester->execute(['email' => 'invalido', '--name' => 'X', '--installation' => 'conta-a', '--generate-password' => true]));

        self::assertSame(Command::SUCCESS, $this->tester->execute(['email' => 'x@y.test', '--name' => 'X', '--installation' => 'conta-a', '--generate-password' => true]));
        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'X@Y.test', '--name' => 'X', '--installation' => 'conta-a', '--generate-password' => true]));
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }
}
