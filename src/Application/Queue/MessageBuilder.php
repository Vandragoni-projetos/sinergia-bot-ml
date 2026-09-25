<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

/**
 * Mensagem FIXA (plano F1: foto, título, preço anterior, preço atual, desconto, link de afiliado). Sem IA.
 *
 *   {título}
 *
 *   De R$ {preço anterior} por R$ {preço atual} ({desconto}% OFF)   ← só com preço anterior válido (> atual)
 *   Por R$ {preço atual}                                            ← sem preço anterior válido: sem "De" e sem desconto
 *
 *   {link de afiliado EXATAMENTE como gravado}
 *
 * A foto vai como imagem da mensagem (legenda = texto acima). O link nunca é alterado, encurtado ou complementado.
 */
final class MessageBuilder
{
    public const string FORMAT_VERSION = 'v1';

    public function caption(string $title, int $priceCents, ?int $originalCents, string $affiliateUrl): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $priceLine = $originalCents !== null && $originalCents > $priceCents
            ? sprintf('De R$ %s por R$ %s (%d%% OFF)', self::money($originalCents), self::money($priceCents), self::discount($priceCents, $originalCents))
            : sprintf('Por R$ %s', self::money($priceCents));

        return $title . "\n\n" . $priceLine . "\n\n" . $affiliateUrl;
    }

    public static function discount(int $priceCents, int $originalCents): int
    {
        return $originalCents > $priceCents ? intdiv(($originalCents - $priceCents) * 100, $originalCents) : 0;
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }
}
