<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Port\Auth\UserRecord;
use Sinergia\Application\Port\Auth\UserStore;
use Sinergia\Domain\Installation\InstallationId;

final class UserRepository implements UserStore
{
    private const string SELECT = 'SELECT u.id, u.installation_id, u.email, u.name, u.password_hash, u.status,
                                          i.name AS installation_name, i.status AS installation_status
                                   FROM users u
                                   JOIN installations i ON i.id = u.installation_id';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function findByEmail(string $normalizedEmail): ?UserRecord
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE u.email = :email');
        $stmt->execute(['email' => $normalizedEmail]);

        return $this->hydrate($stmt->fetch());
    }

    public function findInInstallation(InstallationId $installation, int $userId): ?UserRecord
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE u.installation_id = :inst AND u.id = :id');
        $stmt->execute(['inst' => $installation->value, 'id' => $userId]);

        return $this->hydrate($stmt->fetch());
    }

    public function create(InstallationId $installation, string $normalizedEmail, string $name, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (installation_id, email, name, password_hash) VALUES (:inst, :email, :name, :hash)'
        );
        $stmt->execute(['inst' => $installation->value, 'email' => $normalizedEmail, 'name' => $name, 'hash' => $passwordHash]);

        return (int) $this->pdo->lastInsertId();
    }

    public function recordLogin(InstallationId $installation, int $userId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = :now WHERE installation_id = :inst AND id = :id');
        $stmt->execute(['now' => $now->format('Y-m-d H:i:s.v'), 'inst' => $installation->value, 'id' => $userId]);
    }

    public function updatePasswordHash(InstallationId $installation, int $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE installation_id = :inst AND id = :id');
        $stmt->execute(['hash' => $passwordHash, 'inst' => $installation->value, 'id' => $userId]);
    }

    private function hydrate(mixed $row): ?UserRecord
    {
        if (!is_array($row)) {
            return null;
        }

        return new UserRecord(
            id: (int) $row['id'],
            installationId: new InstallationId((int) $row['installation_id']),
            installationName: (string) $row['installation_name'],
            installationStatus: (string) $row['installation_status'],
            email: (string) $row['email'],
            name: (string) $row['name'],
            passwordHash: (string) $row['password_hash'],
            status: (string) $row['status'],
        );
    }
}
