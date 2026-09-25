<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Sinergia\Application\Offer\CatalogProduct;
use Sinergia\Application\Offer\ChosenOffer;
use Sinergia\Application\Offer\OfferChooser;
use Sinergia\Application\Offer\ProductOffer;

final class OfferChooserTest extends TestCase
{
    public function testBuyBoxWinnerIsTheOfferWhenEligible(): void
    {
        $chosen = (new OfferChooser())->chooseFromBuyBox($this->product(new ProductOffer('MLB10', 16100, 21990, 'BRL', 'new', true, false)));

        self::assertNotNull($chosen);
        self::assertSame(ChosenOffer::RULE_BUY_BOX, $chosen->rule);
        self::assertSame(16100, $chosen->priceCents);
        self::assertSame(21990, $chosen->originalPriceCents);
        self::assertSame(26, $chosen->discountPct, '(219,90 − 161) / 219,90 = 26,78% → 26 (arredonda para baixo)');
        self::assertNull($chosen->offersTotal);
    }

    public function testIneligibleBuyBoxFallsBackToItems(): void
    {
        $chooser = new OfferChooser();
        self::assertNull($chooser->chooseFromBuyBox($this->product(null)));
        self::assertNull($chooser->chooseFromBuyBox($this->product(new ProductOffer('MLB10', 16100, null, 'USD', 'new', false, false))));
        self::assertNull($chooser->chooseFromBuyBox($this->product(new ProductOffer('MLB10', 16100, null, 'BRL', 'used', false, false))));
    }

    public function testLowestPriceWinsRegardlessOfApiOrder(): void
    {
        $offers = [
            new ProductOffer('MLB3', 19900, 25000, 'BRL', 'new', true, true),
            new ProductOffer('MLB2', 15000, null, 'BRL', 'new', false, false),
            new ProductOffer('MLB1', 17000, 30000, 'BRL', 'new', true, false),
        ];
        foreach ([$offers, array_reverse($offers), [$offers[1], $offers[0], $offers[2]]] as $order) {
            $chosen = (new OfferChooser())->chooseFromItems($order);
            self::assertSame('MLB2', $chosen?->itemId);
            self::assertSame(ChosenOffer::RULE_LOWEST_PRICE, $chosen->rule);
            self::assertNull($chosen->originalPriceCents);
            self::assertSame(0, $chosen->discountPct, 'Sem preço anterior não há desconto (não é inventado a partir de outras ofertas).');
            self::assertSame(3, $chosen->offersTotal);
            self::assertSame(3, $chosen->offersEligible);
        }
    }

    public function testTieBreakers(): void
    {
        $chooser = new OfferChooser();
        // Mesmo preço: frete grátis > loja oficial > maior desconto > menor item_id.
        self::assertSame('MLB9', $chooser->chooseFromItems([
            new ProductOffer('MLB1', 10000, null, 'BRL', 'new', false, true),
            new ProductOffer('MLB9', 10000, null, 'BRL', 'new', true, false),
        ])?->itemId);
        self::assertSame('MLB8', $chooser->chooseFromItems([
            new ProductOffer('MLB1', 10000, null, 'BRL', 'new', true, false),
            new ProductOffer('MLB8', 10000, null, 'BRL', 'new', true, true),
        ])?->itemId);
        self::assertSame('MLB7', $chooser->chooseFromItems([
            new ProductOffer('MLB1', 10000, 11000, 'BRL', 'new', true, true),
            new ProductOffer('MLB7', 10000, 20000, 'BRL', 'new', true, true),
        ])?->itemId);
        self::assertSame('MLB1', $chooser->chooseFromItems([
            new ProductOffer('MLB5', 10000, null, 'BRL', 'new', true, true),
            new ProductOffer('MLB1', 10000, null, 'BRL', 'new', true, true),
        ])?->itemId);
    }

    public function testIneligibleOffersAreIgnored(): void
    {
        $chooser = new OfferChooser();
        $chosen = $chooser->chooseFromItems([
            new ProductOffer('MLB1', 5000, null, 'USD', 'new', true, true),
            new ProductOffer('MLB2', 6000, null, 'BRL', 'used', true, true),
            new ProductOffer('MLB3', 0, null, 'BRL', 'new', true, true),
            new ProductOffer('MLB4', 9000, null, 'BRL', null, false, false),
        ]);
        self::assertSame('MLB4', $chosen?->itemId, 'Condição ausente é aceita.');
        self::assertSame(1, $chosen->offersEligible);
        self::assertNull($chooser->chooseFromItems([]));
        self::assertNull($chooser->chooseFromItems([new ProductOffer('MLB1', 5000, null, 'BRL', 'refurbished', true, true)]));
    }

    public function testOriginalPriceNotHigherThanCurrentMeansNoDiscount(): void
    {
        $offer = new ProductOffer('MLB1', 10000, 9000, 'BRL', 'new', false, false);
        self::assertSame(0, $offer->discountPct());
        self::assertNull($offer->effectiveOriginalCents());
        self::assertSame(0, (new ProductOffer('MLB1', 10000, 10000, 'BRL', 'new', false, false))->discountPct());
        self::assertSame(9, (new ProductOffer('MLB1', 9100, 10000, 'BRL', 'new', false, false))->discountPct());
    }

    private function product(?ProductOffer $buyBox): CatalogProduct
    {
        return new CatalogProduct('MLB74960150', 'Produto', 'MLB-AIR_FRYERS', 'https://www.mercadolivre.com.br/p/MLB74960150', null, 0, 'active', $buyBox);
    }
}
