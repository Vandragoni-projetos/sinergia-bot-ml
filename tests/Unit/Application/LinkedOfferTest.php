<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sinergia\Application\Affiliate\OfferItemUrl;
use Sinergia\Application\Affiliate\ProductToLink;
use Sinergia\Application\Offer\CatalogProduct;
use Sinergia\Application\Offer\ChosenOffer;
use Sinergia\Application\Offer\OfferChooser;
use Sinergia\Application\Offer\ProductOffer;

/**
 * Etapa 12C: catalog_product_id ≠ item_id ≠ source_url ≠ affiliate_url.
 * A URL entregue ao Gerador é a do anúncio escolhido; no envio vale só o anúncio vinculado ao link.
 */
final class LinkedOfferTest extends TestCase
{
    public function testItemUrlIsDeterministicAndMatchesTheValidatedFormat(): void
    {
        self::assertSame('https://produto.mercadolivre.com.br/MLB-4445311021', OfferItemUrl::fromItemId('MLB4445311021'));
        self::assertSame('https://produto.mercadolivre.com.br/MLB-7', OfferItemUrl::fromItemId('MLB7'));
        self::assertTrue(OfferItemUrl::isValidItemId('MLB4445311021'));
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidItemIds(): iterable
    {
        yield 'nulo' => [null];
        yield 'vazio' => [''];
        yield 'só o site' => ['MLB'];
        yield 'outro site' => ['MLA4445311021'];
        yield 'minúsculo' => ['mlb4445311021'];
        yield 'com hífen' => ['MLB-4445311021'];
        yield 'user product' => ['MLBU3368462617'];
        yield 'letra no meio' => ['MLB44453110a1'];
        yield 'quebra de linha no fim' => ["MLB4445311021\n"];
        yield 'espaço' => [' MLB4445311021'];
        yield 'longo demais' => ['MLB' . str_repeat('1', 21)];
        yield 'URL no lugar do id' => ['https://produto.mercadolivre.com.br/MLB-4445311021'];
    }

    #[DataProvider('invalidItemIds')]
    public function testWithoutAValidItemIdThereIsNoUrlAndTheProductIsBlocked(?string $itemId): void
    {
        self::assertNull(OfferItemUrl::fromItemId($itemId));

        $product = new ProductToLink('MLB41221309', $itemId, 'Kit 3 Perfumes');
        self::assertFalse($product->exportable());
        self::assertNull($product->originalUrl, 'Nunca /p/{produto} nem permalink como substituto silencioso.');
        self::assertSame(ProductToLink::BLOCKED_NO_OFFER, $product->blockedReason);
    }

    public function testProductToLinkKeepsTheFourConceptsApart(): void
    {
        $product = new ProductToLink('MLB41221309', 'MLB4445311021', 'Kit 3 Perfumes');

        self::assertTrue($product->exportable());
        self::assertSame('MLB41221309', $product->productId);
        self::assertSame('MLB4445311021', $product->offerItemId);
        self::assertSame('https://produto.mercadolivre.com.br/MLB-4445311021', $product->originalUrl);
        self::assertNull($product->blockedReason);
    }

    public function testLinkedOfferUsesOnlyTheLinkedItemEvenWhenAnotherIsCheaper(): void
    {
        $chooser = new OfferChooser();
        $offers = [self::offer('MLB5000000001', 4000), self::offer('MLB4445311021', 5200, 11970), self::offer('MLB5270155247', 5423)];

        // A regra de seleção escolheria o mais barato…
        self::assertSame('MLB5000000001', $chooser->chooseFromItems($offers)?->itemId);
        // …mas o envio usa SÓ o anúncio do link, com o preço atual dele.
        $linked = $chooser->linked(self::product(null), $offers, 'MLB4445311021');
        self::assertNotNull($linked);
        self::assertSame('MLB4445311021', $linked->itemId);
        self::assertSame(5200, $linked->priceCents);
        self::assertSame(11970, $linked->originalPriceCents);
        self::assertSame(56, $linked->discountPct);
        self::assertSame(ChosenOffer::RULE_LINKED_OFFER, $linked->rule);
    }

    public function testLinkedOfferIsFoundInTheBuyBoxWithoutTheItemsList(): void
    {
        $linked = (new OfferChooser())->linked(self::product(self::offer('MLB4445311021', 5200)), [], 'MLB4445311021');

        self::assertSame(5200, $linked?->priceCents);
    }

    public function testMissingOrIneligibleLinkedOfferIsNeverReplaced(): void
    {
        $chooser = new OfferChooser();

        self::assertNull($chooser->linked(self::product(null), [self::offer('MLB5000000001', 4000)], 'MLB4445311021'), 'Sumiu: não troca pelo outro.');
        self::assertNull($chooser->linked(self::product(null), [new ProductOffer('MLB4445311021', 5200, null, 'BRL', 'used', false, false)], 'MLB4445311021'), 'Usado: inelegível.');
        self::assertNull($chooser->linked(self::product(null), [new ProductOffer('MLB4445311021', 5200, null, 'USD', 'new', false, false)], 'MLB4445311021'), 'Outra moeda: inelegível.');
    }

    private static function offer(string $itemId, int $priceCents, ?int $originalCents = null): ProductOffer
    {
        return new ProductOffer($itemId, $priceCents, $originalCents, 'BRL', 'new', false, false);
    }

    private static function product(?ProductOffer $buyBox): CatalogProduct
    {
        // permalink null: a API devolve vazio e isso não importa para o envio.
        return new CatalogProduct('MLB41221309', 'Kit 3 Perfumes', 'MLB-PERFUMES', null, 'https://http2.mlstatic.com/D_x.jpg', 1, 'active', $buyBox);
    }
}
