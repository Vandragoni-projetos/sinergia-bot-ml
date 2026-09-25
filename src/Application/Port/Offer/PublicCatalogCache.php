<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Offer;

use Sinergia\Application\Offer\CatalogProduct;
use Sinergia\Application\Offer\ChosenOffer;
use Sinergia\Application\Port\MercadoLivre\HighlightEntry;

/**
 * Cache de dados PÚBLICOS do catálogo (rankings, produtos, oferta escolhida). Sem installation_id:
 * é compartilhado entre contas e nunca guarda preferência, filtro ou escolha de nenhuma conta.
 */
interface PublicCatalogCache
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_NOT_FOUND = 'not_found';
    public const string STATUS_FORBIDDEN = 'forbidden';
    public const string STATUS_NO_OFFER = 'no_offer';

    /** @return array{entries: list<HighlightEntry>, fetched_at: \DateTimeImmutable}|null */
    public function ranking(string $siteId, string $categoryId): ?array;

    /** @param list<HighlightEntry> $entries */
    public function saveRanking(string $siteId, string $categoryId, array $entries, \DateTimeImmutable $now): void;

    /** @return array{status: string, product: ?CatalogProduct, fetched_at: \DateTimeImmutable}|null */
    public function product(string $productId): ?array;

    public function saveProduct(string $productId, string $siteId, string $status, ?CatalogProduct $product, \DateTimeImmutable $now): void;

    /** @return array{status: string, offer: ?ChosenOffer, fetched_at: \DateTimeImmutable}|null */
    public function offer(string $productId): ?array;

    public function saveOffer(string $productId, string $status, ?ChosenOffer $offer, \DateTimeImmutable $now): void;
}
