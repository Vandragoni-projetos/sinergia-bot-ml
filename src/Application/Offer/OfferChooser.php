<?php

declare(strict_types=1);

namespace Sinergia\Application\Offer;

/**
 * Regra DETERMINÍSTICA de escolha da oferta de um produto de catálogo:
 *
 *  1. Se /products/{id} informa buy_box_winner elegível, ele é a oferta: é o anúncio que a página
 *     do produto (permalink) mostra ao comprador; anunciar outro preço geraria divergência.
 *  2. Senão, entre as ofertas elegíveis de /products/{id}/items (BRL, preço > 0, novo), vence:
 *       a) menor preço;  b) frete grátis;  c) loja oficial;  d) maior desconto;  e) menor item_id (ordem textual).
 *     A ordem em que a API devolve os itens NUNCA é usada como critério.
 *  3. Nenhuma oferta elegível → null (produto descartado).
 */
final class OfferChooser
{
    public function chooseFromBuyBox(CatalogProduct $product): ?ChosenOffer
    {
        $winner = $product->buyBoxWinner;
        if ($winner === null || !$winner->isEligible()) {
            return null;
        }

        return self::chosen($winner, ChosenOffer::RULE_BUY_BOX, null, null);
    }

    /** @param list<ProductOffer> $offers */
    public function chooseFromItems(array $offers): ?ChosenOffer
    {
        $eligible = array_values(array_filter($offers, static fn (ProductOffer $o): bool => $o->isEligible()));
        if ($eligible === []) {
            return null;
        }

        usort($eligible, static fn (ProductOffer $a, ProductOffer $b): int => [$a->priceCents, !$a->freeShipping, !$a->officialStore, -$a->discountPct(), $a->itemId]
            <=> [$b->priceCents, !$b->freeShipping, !$b->officialStore, -$b->discountPct(), $b->itemId]);

        return self::chosen($eligible[0], ChosenOffer::RULE_LOWEST_PRICE, count($offers), count($eligible));
    }

    private static function chosen(ProductOffer $offer, string $rule, ?int $total, ?int $eligible): ChosenOffer
    {
        return new ChosenOffer(
            $offer->itemId,
            $offer->priceCents,
            $offer->effectiveOriginalCents(),
            $offer->discountPct(),
            $rule,
            $offer->freeShipping,
            $offer->officialStore,
            $total,
            $eligible,
        );
    }
}
