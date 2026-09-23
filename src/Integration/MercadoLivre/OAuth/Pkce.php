<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\OAuth;

use Sinergia\Shared\Config\SensitiveValue;

/** PKCE (RFC 7636) com método S256, conforme a documentação oficial de autenticação. */
final class Pkce
{
    public static function generateVerifier(): SensitiveValue
    {
        // 64 bytes aleatórios => 86 caracteres base64url (limite RFC: 43..128).
        return new SensitiveValue(self::base64Url(random_bytes(64)));
    }

    public static function challenge(SensitiveValue $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier->reveal(), true));
    }

    public static function generateState(): SensitiveValue
    {
        return new SensitiveValue(self::base64Url(random_bytes(32)));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
