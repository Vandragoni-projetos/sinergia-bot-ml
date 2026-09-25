<?php

declare(strict_types=1);

namespace Sinergia\Application\Auth;

use Sinergia\Shared\Config\SensitiveValue;

final class PasswordHasher
{
    public const int MIN_LENGTH = 12;

    private ?string $dummyHash = null;

    public function hash(SensitiveValue $password): string
    {
        return password_hash($password->reveal(), self::algorithm());
    }

    public function verify(SensitiveValue $password, string $hash): bool
    {
        return password_verify($password->reveal(), $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    /** Gasta o mesmo tempo de uma verificação real (e-mail inexistente não fica mais rápido). */
    public function verifyAgainstDummy(SensitiveValue $password): void
    {
        $this->dummyHash ??= password_hash(bin2hex(random_bytes(16)), self::algorithm());
        password_verify($password->reveal(), $this->dummyHash);
    }

    /** @return list<string> problemas encontrados (vazio = senha aceita) */
    public static function policyViolations(SensitiveValue $password): array
    {
        $problems = [];
        if (mb_strlen($password->reveal()) < self::MIN_LENGTH) {
            $problems[] = sprintf('A senha precisa ter pelo menos %d caracteres.', self::MIN_LENGTH);
        }
        if (mb_strlen($password->reveal()) > 256) {
            $problems[] = 'A senha pode ter no máximo 256 caracteres.';
        }

        return $problems;
    }

    public static function generate(): SensitiveValue
    {
        return new SensitiveValue(rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Ab'), '='));
    }

    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
