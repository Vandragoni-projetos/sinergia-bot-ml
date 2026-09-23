<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\MercadoLivre;

use PHPUnit\Framework\TestCase;
use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Integration\MercadoLivre\Exception\InvalidArgumentException;
use Sinergia\Integration\MercadoLivre\Exception\InvalidResponseException;
use Sinergia\Integration\MercadoLivre\Exception\NotFoundException;
use Sinergia\Integration\MercadoLivre\Highlight\HighlightService;
use Sinergia\Tests\Support\FakeMercadoLivre;

final class HighlightServiceTest extends TestCase
{
    public function testParsesDocumentedStructure(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, FakeMercadoLivre::fixture('highlights_category.json'));

        $result = (new HighlightService($fake->client))->topByCategory('MLB', 'MLB432825', AuthMode::Required);

        self::assertSame('/highlights/MLB/category/MLB432825', $fake->lastRequest()->getUri()->getPath());
        self::assertSame(5, $result->count());
        self::assertSame('BEST_SELLER', $result->highlightType);
        self::assertSame('CATEGORY', $result->criteria);
        self::assertSame('MLB432825', $result->queryId);
        self::assertSame([], $result->warnings);
        self::assertSame([1, 2, 3, 4, 5], array_map(static fn ($e) => $e->position, $result->entries));
        self::assertSame('MLBU3013800008', $result->entries[0]->id);
        self::assertSame('USER_PRODUCT', $result->entries[0]->type);
        self::assertSame(['ITEM' => 1, 'PRODUCT' => 2, 'USER_PRODUCT' => 2], $result->countByType());
    }

    public function testEntriesAreSortedByPosition(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['content' => [
            ['id' => 'MLB2', 'position' => 2, 'type' => 'ITEM'],
            ['id' => 'MLB1', 'position' => 1, 'type' => 'ITEM'],
        ]]);

        $result = (new HighlightService($fake->client))->topByCategory('MLB', 'MLB10', AuthMode::Required);

        self::assertSame(['MLB1', 'MLB2'], array_map(static fn ($e) => $e->id, $result->entries));
    }

    public function testOptionalFieldsMissingProduceWarningsNotErrors(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['content' => [
            ['id' => 'MLB1', 'position' => 1],
            ['id' => 'MLB2', 'position' => 2, 'type' => 'NEW_KIND'],
        ]]);

        $result = (new HighlightService($fake->client))->topByCategory('MLB', 'MLB10', AuthMode::Required);

        self::assertSame(2, $result->count());
        self::assertNull($result->highlightType);
        self::assertNull($result->entries[0]->type);
        self::assertCount(3, $result->warnings); // query_data ausente, type ausente, type não documentado
    }

    public function testEmptyContentIsValidAndReturnsZero(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['query_data' => ['highlight_type' => 'BEST_SELLER', 'criteria' => 'CATEGORY', 'id' => 'MLB10'], 'content' => []]);

        $result = (new HighlightService($fake->client))->topByCategory('MLB', 'MLB10', AuthMode::Required);

        self::assertSame(0, $result->count());
    }

    public function testMissingContentIsContractViolation(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['query_data' => []]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('content');
        (new HighlightService($fake->client))->topByCategory('MLB', 'MLB10', AuthMode::Required);
    }

    public function testInvalidPositionIsContractViolation(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['content' => [['id' => 'MLB1', 'position' => '1']]]);

        $this->expectException(InvalidResponseException::class);
        (new HighlightService($fake->client))->topByCategory('MLB', 'MLB10', AuthMode::Required);
    }

    public function testMoreThanDocumentedMaximumIsFlagged(): void
    {
        $content = [];
        for ($i = 1; $i <= 21; $i++) {
            $content[] = ['id' => 'MLB' . $i, 'position' => $i, 'type' => 'ITEM'];
        }
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['query_data' => ['highlight_type' => 'BEST_SELLER', 'id' => 'MLB10'], 'content' => $content]);

        $result = (new HighlightService($fake->client))->topByCategory('MLB', 'MLB10', AuthMode::Required);

        self::assertSame(21, $result->count());
        self::assertStringContainsString('até 20', implode(' ', $result->warnings));
    }

    public function testInvalidCategoryRejectedBeforeNetwork(): void
    {
        $fake = new FakeMercadoLivre();
        $service = new HighlightService($fake->client);

        foreach (['', 'abc', 'MLB', 'MLA123', 'MLB12;DROP', '../MLB1'] as $bad) {
            try {
                $service->topByCategory('MLB', $bad, AuthMode::Required);
                self::fail('Deveria rejeitar: ' . $bad);
            } catch (InvalidArgumentException) {
                // esperado
            }
        }
        self::assertSame([], $fake->history);
    }

    public function testAttributeFilterRequiresBothParts(): void
    {
        $fake = new FakeMercadoLivre();

        $this->expectException(InvalidArgumentException::class);
        (new HighlightService($fake->client))->topByCategory('MLB', 'MLB10', AuthMode::Required, 'BRAND', null);
    }

    public function testAttributeFilterSentAsDocumented(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['content' => []]);

        (new HighlightService($fake->client))->topByCategory('MLB', 'MLB10', AuthMode::Required, 'BRAND', '59387');

        self::assertSame('attribute=BRAND&attributeValue=59387', $fake->lastRequest()->getUri()->getQuery());
    }

    public function testNonLeafCategory404Propagates(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(404, ['message' => 'Dimension CATEGORY with id MLB5 not found', 'error' => 'not_found', 'status' => 404]);

        $this->expectException(NotFoundException::class);
        (new HighlightService($fake->client))->topByCategory('MLB', 'MLB5', AuthMode::Required);
    }
}
