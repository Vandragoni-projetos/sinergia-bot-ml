<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Offer\CatalogProduct;
use Sinergia\Application\Offer\ChosenOffer;
use Sinergia\Application\Port\MercadoLivre\HighlightEntry;
use Sinergia\Application\Port\Offer\PublicCatalogCache;

/** Cache dos dados públicos do catálogo. Tabelas sem installation_id, por desenho. */
final class PublicCatalogCacheRepository implements PublicCatalogCache
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function ranking(string $siteId, string $categoryId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT fetched_at FROM ml_ranking_snapshots WHERE site_id = :site AND ml_category_id = :cat');
        $stmt->execute(['site' => $siteId, 'cat' => $categoryId]);
        $fetchedAt = $stmt->fetchColumn();
        if (!is_string($fetchedAt)) {
            return null;
        }
        $rows = $this->pdo->prepare(
            'SELECT position, ml_id, entry_type FROM ml_ranking_entries WHERE site_id = :site AND ml_category_id = :cat ORDER BY position'
        );
        $rows->execute(['site' => $siteId, 'cat' => $categoryId]);
        $entries = [];
        foreach ($rows->fetchAll() as $row) {
            $entries[] = new HighlightEntry((string) $row['ml_id'], (int) $row['position'], $row['entry_type'] === null ? null : (string) $row['entry_type']);
        }

        return ['entries' => $entries, 'fetched_at' => self::utc($fetchedAt)];
    }

    public function saveRanking(string $siteId, string $categoryId, array $entries, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($siteId, $categoryId, $entries, $now): void {
            $this->pdo->prepare('DELETE FROM ml_ranking_snapshots WHERE site_id = :site AND ml_category_id = :cat')
                ->execute(['site' => $siteId, 'cat' => $categoryId]);
            $this->pdo->prepare('INSERT INTO ml_ranking_snapshots (site_id, ml_category_id, entries, fetched_at) VALUES (:site, :cat, :n, :at)')
                ->execute(['site' => $siteId, 'cat' => $categoryId, 'n' => count($entries), 'at' => self::ts($now)]);
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO ml_ranking_entries (site_id, ml_category_id, position, ml_id, entry_type) VALUES (:site, :cat, :pos, :id, :type)'
            );
            foreach ($entries as $entry) {
                $insert->execute([
                    'site' => $siteId, 'cat' => $categoryId, 'pos' => $entry->position,
                    'id' => mb_substr($entry->id, 0, 32), 'type' => $entry->type === null ? null : mb_substr($entry->type, 0, 16),
                ]);
            }
        });
    }

    public function product(string $productId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ml_products WHERE ml_product_id = :id');
        $stmt->execute(['id' => $productId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $product = $row['status'] !== PublicCatalogCache::STATUS_OK ? null : new CatalogProduct(
            (string) $row['ml_product_id'],
            (string) $row['name'],
            $row['domain_id'] === null ? null : (string) $row['domain_id'],
            $row['permalink'] === null ? null : (string) $row['permalink'],
            $row['picture_url'] === null ? null : (string) $row['picture_url'],
            (int) $row['pictures_count'],
            $row['catalog_status'] === null ? null : (string) $row['catalog_status'],
            // O buy_box_winner não é cacheado aqui: a oferta escolhida fica em ml_product_offers.
            null,
        );

        return ['status' => (string) $row['status'], 'product' => $product, 'fetched_at' => self::utc((string) $row['fetched_at'])];
    }

    public function saveProduct(string $productId, string $siteId, string $status, ?CatalogProduct $product, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO ml_products (ml_product_id, site_id, status, name, domain_id, permalink, picture_url, pictures_count, catalog_status, fetched_at)
             VALUES (:id, :site, :status, :name, :domain, :permalink, :picture, :pictures, :cstatus, :at)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                name = IF(VALUES(status) = \'ok\', VALUES(name), name),
                domain_id = IF(VALUES(status) = \'ok\', VALUES(domain_id), domain_id),
                permalink = IF(VALUES(status) = \'ok\', VALUES(permalink), permalink),
                picture_url = IF(VALUES(status) = \'ok\', VALUES(picture_url), picture_url),
                pictures_count = IF(VALUES(status) = \'ok\', VALUES(pictures_count), pictures_count),
                catalog_status = IF(VALUES(status) = \'ok\', VALUES(catalog_status), catalog_status),
                fetched_at = VALUES(fetched_at)'
        )->execute([
            'id' => $productId,
            'site' => $siteId,
            'status' => $status,
            'name' => $product?->name,
            'domain' => $product?->domainId,
            'permalink' => $product?->permalink,
            'picture' => $product?->pictureUrl,
            'pictures' => $product === null ? 0 : $product->picturesCount,
            'cstatus' => $product?->catalogStatus,
            'at' => self::ts($now),
        ]);
    }

    public function offer(string $productId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ml_product_offers WHERE ml_product_id = :id');
        $stmt->execute(['id' => $productId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $offer = $row['status'] !== PublicCatalogCache::STATUS_OK ? null : new ChosenOffer(
            (string) $row['item_id'],
            self::cents((string) $row['price']),
            $row['original_price'] === null ? null : self::cents((string) $row['original_price']),
            (int) $row['discount_pct'],
            (string) $row['selection_rule'],
            (bool) $row['free_shipping'],
            (bool) $row['official_store'],
            $row['offers_total'] === null ? null : (int) $row['offers_total'],
            $row['offers_eligible'] === null ? null : (int) $row['offers_eligible'],
        );

        return ['status' => (string) $row['status'], 'offer' => $offer, 'fetched_at' => self::utc((string) $row['fetched_at'])];
    }

    public function saveOffer(string $productId, string $status, ?ChosenOffer $offer, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'REPLACE INTO ml_product_offers
                (ml_product_id, status, item_id, price, original_price, currency_id, discount_pct, selection_rule,
                 free_shipping, official_store, offers_total, offers_eligible, fetched_at)
             VALUES (:id, :status, :item, :price, :original, :currency, :discount, :rule, :free, :official, :total, :eligible, :at)'
        )->execute([
            'id' => $productId,
            'status' => $status,
            'item' => $offer?->itemId,
            'price' => self::decimal($offer?->priceCents),
            'original' => self::decimal($offer?->originalPriceCents),
            'currency' => $offer === null ? null : 'BRL',
            'discount' => $offer?->discountPct,
            'rule' => $offer?->rule,
            'free' => $offer === null ? null : (int) $offer->freeShipping,
            'official' => $offer === null ? null : (int) $offer->officialStore,
            'total' => $offer?->offersTotal === null ? null : min($offer->offersTotal, 65535),
            'eligible' => $offer?->offersEligible === null ? null : min($offer->offersEligible, 65535),
            'at' => self::ts($now),
        ]);
    }

    private function transaction(callable $work): void
    {
        $this->pdo->beginTransaction();
        try {
            $work();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public static function decimal(?int $cents): ?string
    {
        return $cents === null ? null : sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    public static function cents(string $decimal): int
    {
        [$int, $frac] = array_pad(explode('.', $decimal), 2, '0');

        return (int) $int * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
    }

    private static function ts(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private static function utc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
