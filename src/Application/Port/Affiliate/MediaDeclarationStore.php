<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Affiliate;

use Sinergia\Domain\Installation\InstallationId;

/** Declaração de Mídias do afiliado, com histórico por conta e usuário. */
interface MediaDeclarationStore
{
    /** @return array{user_id: int, user_name: string, version: string, declared_at: \DateTimeImmutable}|null */
    public function current(InstallationId $installation): ?array;

    public function declare(InstallationId $installation, int $userId, string $version, \DateTimeImmutable $now): void;

    public function revoke(InstallationId $installation, int $userId, \DateTimeImmutable $now): void;
}
