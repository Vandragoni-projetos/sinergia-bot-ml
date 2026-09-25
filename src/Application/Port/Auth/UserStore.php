<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Auth;

use Sinergia\Domain\Installation\InstallationId;

interface UserStore
{
    public function findByEmail(string $normalizedEmail): ?UserRecord;

    /** Só encontra o usuário dentro da própria conta: nunca atravessa installation_id. */
    public function findInInstallation(InstallationId $installation, int $userId): ?UserRecord;

    public function create(InstallationId $installation, string $normalizedEmail, string $name, string $passwordHash): int;

    public function recordLogin(InstallationId $installation, int $userId, \DateTimeImmutable $now): void;

    public function updatePasswordHash(InstallationId $installation, int $userId, string $passwordHash): void;
}
