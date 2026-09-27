<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/**
 * Linha em que o Gerador de Links do Mercado Livre RECUSOU a URL, copiada da caixa de resultado:
 *   "⚠ Este URL não é permitido pelo Programa."
 * Ela ocupa a MESMA posição da URL recusada, então preserva o alinhamento do lote: o produto daquela posição fica sem
 * link (recusado pelo Programa) e as demais posições continuam 1→1, sem deslocar nada.
 * Só vale como marcador de posição; nunca é tratada como link.
 */
final class ProgramRefusal
{
    public const string REASON = 'affiliate_program_rejected';

    public static function matches(string $line): bool
    {
        $text = mb_strtolower(trim($line));

        return preg_match('/^(?:[\x{26A0}\x{FE0F}\s!]*)?este\s+url\s+n(?:ã|a)o\s+(?:é|e)\s+permitido\s+pelo\s+programa\.?$/u', $text) === 1;
    }
}
