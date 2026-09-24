<?php

declare(strict_types=1);

namespace Sinergia\Application\OAuth;

use Sinergia\Shared\Config\SensitiveValue;

/** Parâmetros devolvidos pelo Mercado Livre na redirect_uri (?code=...&state=... ou ?error=...). */
final readonly class OAuthCallbackParameters
{
    private function __construct(
        public ?SensitiveValue $code,
        public ?SensitiveValue $state,
        public ?string $error,
    ) {
    }

    /** @param array<array-key, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $error = self::nonEmpty($query['error'] ?? null);

        return new self(
            ($code = self::nonEmpty($query['code'] ?? null)) === null ? null : new SensitiveValue($code),
            ($state = self::nonEmpty($query['state'] ?? null)) === null ? null : new SensitiveValue($state),
            // Só um identificador curto e conhecido pode aparecer em mensagens/logs.
            $error === null ? null : (preg_match('/^[a-z_]{1,64}$/', $error) === 1 ? $error : 'unknown_error'),
        );
    }

    /**
     * URL completa colada no terminal. Remove marcadores de "bracketed paste" (ESC[200~ / ESC[201~,
     * inclusive na forma literal ^[[200~) e caracteres de controle que terminais web costumam injetar.
     */
    public static function fromCallbackUrl(string $url): self
    {
        $clean = preg_replace(['/(?:\e|\^\[)\[20[01]~/', '/[\x00-\x20\x7F]+/'], '', $url) ?? '';
        $query = [];
        parse_str((string) parse_url($clean, PHP_URL_QUERY), $query);

        return self::fromQuery($query);
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
