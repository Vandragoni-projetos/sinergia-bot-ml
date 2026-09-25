<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Port\Auth\LoginAttemptStore;

final class LoginAttemptRepository implements LoginAttemptStore
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function record(string $emailHash, string $ip, bool $succeeded, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (email_hash, ip, succeeded, attempted_at) VALUES (:email, :ip, :ok, :at)'
        );
        $stmt->execute(['email' => $emailHash, 'ip' => $ip, 'ok' => $succeeded ? 1 : 0, 'at' => $now->format('Y-m-d H:i:s.v')]);
    }

    public function failuresForEmail(string $emailHash, \DateTimeImmutable $since): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email_hash = :email AND succeeded = 0 AND attempted_at >= :since');
        $stmt->execute(['email' => $emailHash, 'since' => $since->format('Y-m-d H:i:s.v')]);

        return (int) $stmt->fetchColumn();
    }

    public function failuresForIp(string $ip, \DateTimeImmutable $since): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND succeeded = 0 AND attempted_at >= :since');
        $stmt->execute(['ip' => $ip, 'since' => $since->format('Y-m-d H:i:s.v')]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :cutoff');
        $stmt->execute(['cutoff' => $cutoff->format('Y-m-d H:i:s.v')]);

        return $stmt->rowCount();
    }
}
