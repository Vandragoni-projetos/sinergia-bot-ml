<?php

declare(strict_types=1);

namespace Sinergia\Shared\Config;

use Dotenv\Dotenv;

/**
 * Lê variáveis de ambiente. Em desenvolvimento, um arquivo .env local (não versionado)
 * pode complementar o ambiente; variáveis reais do processo sempre têm prioridade.
 */
final class Environment
{
    /** @return array<string, string> */
    public static function load(string $projectRoot): array
    {
        if (is_file($projectRoot . '/.env')) {
            Dotenv::createImmutable($projectRoot)->safeLoad();
        }

        $values = [];
        foreach (array_merge($_ENV, getenv()) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
