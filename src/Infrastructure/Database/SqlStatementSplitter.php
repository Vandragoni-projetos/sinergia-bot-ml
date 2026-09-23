<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Database;

/**
 * Divide um arquivo SQL em instruções pelo ";" final, respeitando strings
 * ('...', "...", `...`) e comentários (-- ..., # ..., /* ... *\/).
 * Não suporta DELIMITER (triggers entrarão em arquivos com separador próprio quando necessário).
 */
final class SqlStatementSplitter
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $buffer .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '-' && $next === '-' || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $buffer .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                self::push($statements, $buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        self::push($statements, $buffer);

        return $statements;
    }

    /** @param list<string> $statements */
    private static function push(array &$statements, string $buffer): void
    {
        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
    }
}
