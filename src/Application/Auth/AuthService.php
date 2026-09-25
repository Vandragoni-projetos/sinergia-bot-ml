<?php

declare(strict_types=1);

namespace Sinergia\Application\Auth;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Port\Auth\LoginAttemptStore;
use Sinergia\Application\Port\Auth\SessionStore;
use Sinergia\Application\Port\Auth\UserStore;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Config\SensitiveValue;

/**
 * Login, sessão e logout do painel.
 *
 * - Sessão: token aleatório de 256 bits no cookie; no banco só o SHA-256 (sobrevive a deploy/restart).
 * - Validade: expira após 12 h sem uso e, no máximo, 7 dias após o login.
 * - Abuso: no máximo 5 falhas por e-mail e 20 por IP a cada 15 minutos.
 * - Mensagem de erro única (não revela se o e-mail existe).
 */
final class AuthService
{
    public const int MAX_FAILURES_PER_EMAIL = 5;
    public const int MAX_FAILURES_PER_IP = 20;
    public const string THROTTLE_WINDOW = 'PT15M';
    public const string IDLE_TIMEOUT = 'PT12H';
    public const string ABSOLUTE_LIFETIME = 'P7D';
    private const int TOUCH_EVERY_SECONDS = 300;

    public function __construct(
        private readonly UserStore $users,
        private readonly SessionStore $sessions,
        private readonly LoginAttemptStore $attempts,
        private readonly PasswordHasher $hasher,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function login(string $email, SensitiveValue $password, string $ip, ?string $userAgent): LoginResult
    {
        $now = $this->clock->now();
        $normalized = self::normalizeEmail($email);
        $emailHash = hash('sha256', $normalized);
        $since = $now->sub(new \DateInterval(self::THROTTLE_WINDOW));

        if ($this->attempts->failuresForEmail($emailHash, $since) >= self::MAX_FAILURES_PER_EMAIL
            || $this->attempts->failuresForIp($ip, $since) >= self::MAX_FAILURES_PER_IP) {
            $this->logger->warning('auth.login_throttled', ['email_hash' => substr($emailHash, 0, 12)]);

            return LoginResult::throttled();
        }

        $user = $normalized === '' ? null : $this->users->findByEmail($normalized);
        if ($user === null) {
            $this->hasher->verifyAgainstDummy($password);
            $this->attempts->record($emailHash, $ip, false, $now);

            return LoginResult::invalid();
        }

        if (!$this->hasher->verify($password, $user->passwordHash) || !$user->canSignIn()) {
            $this->attempts->record($emailHash, $ip, false, $now);
            $this->logger->info('auth.login_failed', ['installation_id' => $user->installationId->value, 'user_id' => $user->id]);

            return LoginResult::invalid();
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash($user->installationId, $user->id, $this->hasher->hash($password));
        }

        $token = new SensitiveValue(self::base64Url(random_bytes(32)));
        $this->sessions->create(
            self::hashToken($token),
            $user->installationId,
            $user->id,
            $now,
            $this->expiry($now, $now),
            $ip,
            $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        );
        $this->users->recordLogin($user->installationId, $user->id, $now);
        $this->attempts->record($emailHash, $ip, true, $now);
        $this->logger->info('auth.login_succeeded', ['installation_id' => $user->installationId->value, 'user_id' => $user->id]);

        return LoginResult::ok($token, new TenantContext(
            $user->installationId,
            $user->installationName,
            $user->id,
            $user->name,
            $user->email,
        ));
    }

    /** Resolve o cookie de sessão em conta + usuário; null se ausente, expirado, revogado ou inativo. */
    public function resolve(?string $rawToken): ?TenantContext
    {
        if ($rawToken === null || preg_match('/^[A-Za-z0-9_-]{43}$/', $rawToken) !== 1) {
            return null;
        }

        $now = $this->clock->now();
        $hash = self::hashToken(new SensitiveValue($rawToken));
        $session = $this->sessions->findActive($hash, $now);
        if ($session === null) {
            return null;
        }

        if ($now->getTimestamp() - $session['last_seen_at']->getTimestamp() >= self::TOUCH_EVERY_SECONDS) {
            $this->sessions->touch($hash, $now, $this->expiry($session['created_at'], $now));
        }

        return $session['context'];
    }

    public function logout(?string $rawToken): void
    {
        if ($rawToken !== null && $rawToken !== '') {
            $this->sessions->delete(self::hashToken(new SensitiveValue($rawToken)));
        }
    }

    public static function hashToken(SensitiveValue $token): string
    {
        return hash('sha256', $token->reveal());
    }

    private function expiry(\DateTimeImmutable $createdAt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $idle = $now->add(new \DateInterval(self::IDLE_TIMEOUT));
        $absolute = $createdAt->add(new \DateInterval(self::ABSOLUTE_LIFETIME));

        return $idle < $absolute ? $idle : $absolute;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
