<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Port\Auth\SessionStore;
use Sinergia\Domain\Installation\InstallationId;

final class SessionRepository implements SessionStore
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(
        string $tokenHash,
        InstallationId $installation,
        int $userId,
        \DateTimeImmutable $now,
        \DateTimeImmutable $expiresAt,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_sessions (id, installation_id, user_id, created_at, last_seen_at, expires_at, ip, user_agent)
             VALUES (:id, :inst, :user, :created, :seen, :expires, :ip, :ua)'
        );
        $stmt->execute([
            'id' => $tokenHash,
            'inst' => $installation->value,
            'user' => $userId,
            'created' => $now->format('Y-m-d H:i:s.v'),
            'seen' => $now->format('Y-m-d H:i:s.v'),
            'expires' => $expiresAt->format('Y-m-d H:i:s.v'),
            'ip' => $ip,
            'ua' => $userAgent,
        ]);
    }

    public function findActive(string $tokenHash, \DateTimeImmutable $now): ?array
    {
        // A sessão, o usuário e a conta precisam ser da MESMA installation (FK composta garante a
        // gravação; o JOIN confere de novo na leitura) e estar ativos.
        $stmt = $this->pdo->prepare(
            "SELECT s.created_at, s.last_seen_at,
                    u.id AS user_id, u.name AS user_name, u.email,
                    i.id AS installation_id, i.name AS installation_name
             FROM user_sessions s
             JOIN users u ON u.installation_id = s.installation_id AND u.id = s.user_id
             JOIN installations i ON i.id = s.installation_id
             WHERE s.id = :id AND s.expires_at > :now AND u.status = 'active' AND i.status = 'active'"
        );
        $stmt->execute(['id' => $tokenHash, 'now' => $now->format('Y-m-d H:i:s.v')]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        return [
            'context' => new TenantContext(
                new InstallationId((int) $row['installation_id']),
                (string) $row['installation_name'],
                (int) $row['user_id'],
                (string) $row['user_name'],
                (string) $row['email'],
            ),
            'created_at' => new \DateTimeImmutable((string) $row['created_at'], $utc),
            'last_seen_at' => new \DateTimeImmutable((string) $row['last_seen_at'], $utc),
        ];
    }

    public function touch(string $tokenHash, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE user_sessions SET last_seen_at = :now, expires_at = :expires WHERE id = :id');
        $stmt->execute([
            'now' => $now->format('Y-m-d H:i:s.v'),
            'expires' => $expiresAt->format('Y-m-d H:i:s.v'),
            'id' => $tokenHash,
        ]);
    }

    public function delete(string $tokenHash): void
    {
        $this->pdo->prepare('DELETE FROM user_sessions WHERE id = :id')->execute(['id' => $tokenHash]);
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_sessions WHERE expires_at <= :now');
        $stmt->execute(['now' => $now->format('Y-m-d H:i:s.v')]);

        return $stmt->rowCount();
    }
}
