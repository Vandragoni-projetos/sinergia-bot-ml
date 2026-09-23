<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Crypto;

use Sinergia\Shared\Config\SensitiveValue;

/**
 * Cifra autenticada (XSalsa20-Poly1305 via libsodium) para segredos persistidos
 * (tokens OAuth, verificadores PKCE). Formato gravado: base64(nonce || ciphertext).
 * A chave vem do ambiente (APP_KEY) e nunca é persistida no banco.
 */
final class SecretBox
{
    public const string KEY_ID = 'k1';

    public function __construct(private readonly SensitiveValue $key)
    {
        if (strlen($key->reveal()) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new CryptoException('Chave de criptografia com tamanho inválido.');
        }
    }

    public static function generateKeyBase64(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(SensitiveValue $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext->reveal(), $nonce, $this->key->reveal()));
    }

    public function decrypt(string $encoded): SensitiveValue
    {
        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new CryptoException('Segredo cifrado inválido.');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key->reveal());
        if ($plain === false) {
            throw new CryptoException('Falha ao decifrar segredo (chave incorreta ou dado adulterado).');
        }

        return new SensitiveValue($plain);
    }
}
