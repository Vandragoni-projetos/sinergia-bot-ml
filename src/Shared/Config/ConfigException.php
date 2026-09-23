<?php

declare(strict_types=1);

namespace Sinergia\Shared\Config;

/**
 * Erro de configuração. A mensagem cita apenas NOMES de variáveis, nunca valores.
 */
final class ConfigException extends \RuntimeException
{
    /** @param list<string> $keys */
    public static function missing(array $keys): self
    {
        return new self('Configuração obrigatória ausente: ' . implode(', ', $keys) . '.');
    }

    public static function invalid(string $key, string $expectation): self
    {
        return new self(sprintf('Valor inválido para %s (esperado: %s).', $key, $expectation));
    }
}
