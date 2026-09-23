<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\MercadoLivre;

use PHPUnit\Framework\TestCase;
use Sinergia\Integration\MercadoLivre\Category\CategoryService;
use Sinergia\Integration\MercadoLivre\Exception\InvalidArgumentException;
use Sinergia\Integration\MercadoLivre\Exception\InvalidResponseException;
use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Tests\Support\FakeMercadoLivre;

final class CategoryServiceTest extends TestCase
{
    public function testLeafCategoryWithPathAndDomain(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, FakeMercadoLivre::fixture('category_leaf.json'));

        $category = (new CategoryService($fake->client))->category('MLB', 'MLB900002', AuthMode::Required);

        self::assertSame('/categories/MLB900002', $fake->lastRequest()->getUri()->getPath());
        self::assertSame('Categoria Folha Exemplo', $category->name);
        self::assertTrue($category->isLeaf());
        self::assertSame('MLB900001', $category->parentId());
        self::assertSame('Raiz Exemplo > Intermediária Exemplo > Categoria Folha Exemplo', $category->pathLabel());
        self::assertSame('MLB-EXAMPLE_DOMAIN', $category->catalogDomain);
        self::assertSame(12345, $category->totalItems);
    }

    public function testBranchCategoryChildrenAndMissingOptionalFields(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, FakeMercadoLivre::fixture('category_branch.json'));

        $category = (new CategoryService($fake->client))->category('MLB', 'MLB900000', AuthMode::Required);

        self::assertFalse($category->isLeaf());
        self::assertNull($category->parentId(), 'Raiz não tem pai');
        self::assertNull($category->catalogDomain, 'Domínio ausente vira null, não string vazia');
        self::assertNull($category->totalItems);
        self::assertNull($category->permalink);
        self::assertCount(2, $category->children);
        self::assertSame(500, $category->children[0]->totalItems);
    }

    public function testSiteRoots(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, FakeMercadoLivre::fixture('site_roots.json'));

        $roots = (new CategoryService($fake->client))->siteRoots('MLB', AuthMode::None);

        self::assertSame('/sites/MLB/categories', $fake->lastRequest()->getUri()->getPath());
        self::assertFalse($fake->lastRequest()->hasHeader('Authorization'));
        self::assertSame(2, $roots->count());
        self::assertSame('MLB900000', $roots->roots[0]->id);
        self::assertSame(AuthMode::None, $roots->meta->authMode);
        self::assertSame(200, $roots->meta->status);
    }

    public function testCategoryCarriesNeutralResponseMeta(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, FakeMercadoLivre::fixture('category_leaf.json'), ['Set-Cookie' => 'sid=x']);

        $category = (new CategoryService($fake->client))->category('MLB', 'MLB900002', AuthMode::None);

        self::assertNotNull($category->meta);
        self::assertSame('/categories/MLB900002', $category->meta->path);
        self::assertArrayNotHasKey('set-cookie', $category->meta->headers);
        self::assertSame('MLB900002', $category->meta->body['id']);
    }

    public function testCategoryWithoutNameIsContractViolation(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['id' => 'MLB1']);

        $this->expectException(InvalidResponseException::class);
        (new CategoryService($fake->client))->category('MLB', 'MLB1', AuthMode::Required);
    }

    public function testRootsMustBeList(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['id' => 'MLB1', 'name' => 'x']);

        $this->expectException(InvalidResponseException::class);
        (new CategoryService($fake->client))->siteRoots('MLB', AuthMode::Required);
    }

    public function testInvalidCategoryIdRejectedBeforeNetwork(): void
    {
        $fake = new FakeMercadoLivre();

        try {
            (new CategoryService($fake->client))->category('MLB', 'MLB1/../../oauth', AuthMode::Required);
            self::fail('Deveria rejeitar ID inválido.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('invalid_category_id', $e->errorCode);
        }
        self::assertSame([], $fake->history);
    }
}
