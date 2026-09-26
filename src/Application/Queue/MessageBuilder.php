<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

use Sinergia\Application\Port\Copy\OfferCopy;

/**
 * v1 — mensagem FIXA (plano F1: foto, título, preço anterior, preço atual, desconto, link de afiliado). Sem IA.
 * É também o fallback obrigatório da v2.
 *
 *   {título}
 *
 *   De R$ {preço anterior} por R$ {preço atual} ({desconto}% OFF)   ← só com preço anterior válido (> atual)
 *   Por R$ {preço atual}                                            ← sem preço anterior válido: sem "De" e sem desconto
 *
 *   {link de afiliado EXATAMENTE como gravado}
 *
 * v2 — a mesma mensagem com o texto criativo JÁ VALIDADO (gancho no topo, chamada antes do link). Título, linha de
 * preço e link são exatamente os da v1: a IA não participa de nenhum deles.
 *
 *   {gancho}
 *
 *   {título}
 *
 *   {linha de preço — idêntica à v1}
 *
 *   {chamada}
 *
 *   {link de afiliado EXATAMENTE como gravado}
 *
 * A foto vai como imagem da mensagem (legenda = texto acima). O link nunca é alterado, encurtado ou complementado.
 */
final class MessageBuilder
{
    public const string FORMAT_VERSION = 'v1';
    public const string FORMAT_V2 = 'v2';

    public function caption(string $title, int $priceCents, ?int $originalCents, string $affiliateUrl): string
    {
        return self::title($title) . "\n\n" . self::priceLine($priceCents, $originalCents) . "\n\n" . $affiliateUrl;
    }

    /** @param OfferCopy $copy somente a copy devolvida pelo CopyValidator */
    public function captionWithCopy(string $title, int $priceCents, ?int $originalCents, string $affiliateUrl, OfferCopy $copy): string
    {
        return $copy->hook . "\n\n" . self::title($title) . "\n\n" . self::priceLine($priceCents, $originalCents) . "\n\n"
            . $copy->callToAction . "\n\n" . $affiliateUrl;
    }

    public static function discount(int $priceCents, int $originalCents): int
    {
        return $originalCents > $priceCents ? intdiv(($originalCents - $priceCents) * 100, $originalCents) : 0;
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }

    private static function title(string $title): string
    {
        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }

    private static function priceLine(int $priceCents, ?int $originalCents): string
    {
        return $originalCents !== null && $originalCents > $priceCents
            ? sprintf('De R$ %s por R$ %s (%d%% OFF)', self::money($originalCents), self::money($priceCents), self::discount($priceCents, $originalCents))
            : sprintf('Por R$ %s', self::money($priceCents));
    }
}
