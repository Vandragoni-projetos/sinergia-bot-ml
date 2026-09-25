<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Auth;

use Sinergia\Application\Auth\TenantContext;
use Sinergia\Domain\Installation\InstallationId;

/** Sessões do painel. O armazenamento só conhece o SHA-256 do token, nunca o token. */
interface SessionStore
{
    public function create(
        string $tokenHash,
        InstallationId $installation,
        int $userId,
        \DateTimeImmutable $now,
        \DateTimeImmutable $expiresAt,
        ?string $ip,
        ?string $userAgent,
    ): void;

    /**
     * Sessão ainda válida, com usuário e conta ativos e da MESMA installation.
     *
     * @return array{context: TenantContext, created_at: \DateTimeImmutable, last_seen_at: \DateTimeImmutable}|null
     */
    public function findActive(string $tokenHash, \DateTimeImmutable $now): ?array;

    public function touch(string $tokenHash, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void;

    public function delete(string $tokenHash): void;

    public function deleteExpired(\DateTimeImmutable $now): int;
}
