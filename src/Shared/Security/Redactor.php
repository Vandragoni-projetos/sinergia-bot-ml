<?php

declare(strict_types=1);

namespace Sinergia\Shared\Security;

use Sinergia\Shared\Config\SensitiveValue;

/**
 * Remove segredos de textos e estruturas antes de irem para logs, erros ou evidências.
 * Trabalha por NOME de chave (token, secret, password, ...) e por PADRÃO de valor
 * (tokens do Mercado Livre, cabeçalhos Bearer, parâmetros sensíveis em URLs).
 */
final class Redactor
{
    public const string MASK = '[REDACTED]';

    /** Chaves sensíveis como sufixo (ex.: "access_token", "ml_client_secret"). */
    private const string SENSITIVE_KEY = '/(^|[_\-.])(access[_-]?token|refresh[_-]?token|token|secret|client[_-]?secret|password|passwd|pwd|authorization|cookie|set-cookie|api[_-]?key|apikey|code[_-]?verifier|csrf|x-csrf-token|session)$/i';

    /** Chaves sensíveis só quando são a chave inteira (evita mascarar "error_code"). */
    private const string SENSITIVE_KEY_EXACT = '/^(code|state)$/i';

    /** @var list<array{0: string, 1: string}> */
    private const array VALUE_PATTERNS = [
        // Tokens OAuth do Mercado Livre (access e refresh/authorization code).
        ['/APP_USR-[A-Za-z0-9\-_.]+/', self::MASK],
        ['/\bTG-[A-Za-z0-9\-_.]+/', self::MASK],
        // Cabeçalho Authorization em texto livre.
        ['/(Bearer|Basic)\s+[A-Za-z0-9\-_.~+\/=]+/i', '$1 ' . self::MASK],
        // Parâmetros sensíveis em query strings / corpos form-urlencoded.
        ['/(?<![A-Za-z0-9_])((?:access_token|refresh_token|client_secret|code_verifier|code|state|password)=)[^&\s"\']+/i', '$1' . self::MASK],
        // Pares "chave": "valor" em JSON serializado.
        ['/("(?:access_token|refresh_token|client_secret|code_verifier|password|authorization)"\s*:\s*")[^"]*(")/i', '$1' . self::MASK . '$2'],
    ];

    public static function redactText(string $text): string
    {
        foreach (self::VALUE_PATTERNS as [$pattern, $replacement]) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    public static function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY, $key) === 1 || preg_match(self::SENSITIVE_KEY_EXACT, $key) === 1;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function redactArray(array $data, int $depth = 0): array
    {
        if ($depth > 12) {
            return ['_truncated' => true];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = self::MASK;
                continue;
            }
            $out[$key] = match (true) {
                $value instanceof SensitiveValue => self::MASK,
                is_array($value) => self::redactArray($value, $depth + 1),
                is_string($value) => self::redactText($value),
                $value instanceof \Throwable => self::redactText($value::class . ': ' . $value->getMessage()),
                default => $value,
            };
        }

        return $out;
    }
}
