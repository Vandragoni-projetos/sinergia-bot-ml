<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\MercadoLivre;

use PHPUnit\Framework\TestCase;
use Sinergia\Integration\MercadoLivre\Exception\InvalidArgumentException;
use Sinergia\Integration\MercadoLivre\Exception\InvalidResponseException;
use Sinergia\Integration\MercadoLivre\Product\ProductService;
use Sinergia\Tests\Support\FakeMercadoLivre;

final class ProductServiceTest extends TestCase
{
    public function testParsesCatalogProductWithBuyBox(): void
    {
        $ml = new FakeMercadoLivre();
        $ml->queueJson(200, [
            'id' => 'MLB74960150',
            'name' => '  Air Fryer 4L  ',
            'domain_id' => 'MLB-AIR_FRYERS',
            'status' => 'active',
            'permalink' => 'https://www.mercadolivre.com.br/air-fryer/p/MLB74960150',
            'pictures' => [['id' => 'a', 'url' => 'http://inseguro.example/a.jpg'], ['id' => 'b', 'url' => 'https://http2.mlstatic.com/b.jpg'], ['id' => 'c', 'url' => 'https://http2.mlstatic.com/c.jpg']],
            'buy_box_winner' => ['item_id' => 'MLB7693138348', 'price' => 161, 'original_price' => 219.9, 'currency_id' => 'BRL', 'shipping' => ['free_shipping' => true], 'official_store_id' => 123],
        ]);

        $product = (new ProductService($ml->client))->product('MLB74960150');

        self::assertSame('/products/MLB74960150', $ml->lastRequest()->getUri()->getPath());
        self::assertSame('Air Fryer 4L', $product->name);
        self::assertSame('MLB-AIR_FRYERS', $product->domainId);
        self::assertSame('https://http2.mlstatic.com/b.jpg', $product->pictureUrl, 'Só fotos https.');
        self::assertSame(2, $product->picturesCount);
        self::assertSame(16100, $product->buyBoxWinner?->priceCents);
        self::assertSame(21990, $product->buyBoxWinner->originalPriceCents);
        self::assertTrue($product->buyBoxWinner->freeShipping);
        self::assertTrue($product->buyBoxWinner->officialStore);
    }

    public function testUnsafePermalinkAndMissingPicturesBecomeNull(): void
    {
        $ml = new FakeMercadoLivre();
        $ml->queueJson(200, ['id' => 'MLB1', 'name' => 'X', 'permalink' => 'https://phishing.example/mercadolivre.com.br/p/MLB1', 'pictures' => []]);
        $product = (new ProductService($ml->client))->product('MLB1');

        self::assertNull($product->permalink);
        self::assertNull($product->pictureUrl);
        self::assertFalse($product->hasPhoto());
        self::assertNull($product->domainId);
        self::assertNull($product->buyBoxWinner);
    }

    public function testParsesOffersSkippingMalformedRows(): void
    {
        $ml = new FakeMercadoLivre();
        $ml->queueJson(200, ['paging' => ['total' => 40], 'results' => [
            ['item_id' => 'MLB1', 'price' => 133, 'original_price' => 170, 'currency_id' => 'BRL', 'condition' => 'new'],
            ['item_id' => 'nao-e-id', 'price' => 10],
            'lixo',
            ['item_id' => 'MLB2', 'price' => 'caro', 'currency_id' => 'BRL'],
        ]]);
        $result = (new ProductService($ml->client))->offers('MLB70334862');

        self::assertSame('/products/MLB70334862/items', $ml->lastRequest()->getUri()->getPath());
        self::assertSame(40, $result['total']);
        self::assertCount(2, $result['offers']);
        self::assertSame(13300, $result['offers'][0]->priceCents);
        self::assertSame(17000, $result['offers'][0]->originalPriceCents);
        self::assertFalse($result['offers'][1]->isEligible(), 'Preço inválido vira oferta inelegível.');
    }

    public function testRejectsNonCatalogIdsWithoutCallingTheApi(): void
    {
        $ml = new FakeMercadoLivre();
        foreach (['MLBU4037150577', 'MLB1/../items/MLB2', 'ABC1'] as $id) {
            try {
                (new ProductService($ml->client))->product($id);
                self::fail('Deveria rejeitar ' . $id);
            } catch (InvalidArgumentException $e) {
                self::assertSame('invalid_product_id', $e->errorCode);
            }
        }
        self::assertSame([], $ml->history);
    }

    public function testContractViolations(): void
    {
        $ml = new FakeMercadoLivre();
        $ml->queueJson(200, ['id' => 'MLB999', 'name' => 'Outro']);
        $ml->queueJson(200, ['paging' => []]);
        $service = new ProductService($ml->client);

        foreach ([fn () => $service->product('MLB1'), fn () => $service->offers('MLB1')] as $call) {
            try {
                $call();
                self::fail('Deveria violar o contrato.');
            } catch (InvalidResponseException $e) {
                self::assertSame('contract_violation', $e->errorCode);
            }
        }
    }
}
