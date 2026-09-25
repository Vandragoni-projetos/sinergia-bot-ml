<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Affiliate\AffiliateLink;
use Sinergia\Application\Affiliate\BatchPreview;
use Sinergia\Application\Affiliate\BatchView;
use Sinergia\Application\Affiliate\ExportedItem;
use Sinergia\Application\Affiliate\ImportReport;
use Sinergia\Application\Affiliate\ProductToLink;
use Sinergia\Application\Affiliate\ReceivedLine;
use Sinergia\Application\Port\Affiliate\AffiliateLinkStore;
use Sinergia\Domain\Installation\InstallationId;

/** Biblioteca de links e lotes. TODA consulta e escrita filtra por installation_id. O link nunca é alterado. */
final class AffiliateLinkRepository implements AffiliateLinkStore
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function productsAwaitingLink(InstallationId $installation, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.ml_product_id, p.permalink, p.name, MIN(c.sort_order) AS ord
             FROM account_offer_candidates c
             JOIN ml_products p ON p.ml_product_id = c.ml_product_id AND p.permalink IS NOT NULL
             LEFT JOIN affiliate_links l ON l.installation_id = c.installation_id AND l.active_product_id = c.ml_product_id
             WHERE c.installation_id = :inst AND l.id IS NULL
               AND c.run_id = (SELECT MAX(id) FROM offer_selection_runs WHERE installation_id = :inst2 AND status IN (\'completed\', \'partial\'))
             GROUP BY c.ml_product_id, p.permalink, p.name
             ORDER BY ord, c.ml_product_id
             LIMIT ' . max(1, min($limit, 500))
        );
        $stmt->execute(['inst' => $installation->value, 'inst2' => $installation->value]);

        return array_values(array_map(
            static fn (array $r): ProductToLink => new ProductToLink((string) $r['ml_product_id'], (string) $r['permalink'], (string) $r['name']),
            $stmt->fetchAll(),
        ));
    }

    public function countAwaitingLink(InstallationId $installation): int
    {
        return count($this->productsAwaitingLink($installation, 500));
    }

    public function activeLinks(InstallationId $installation, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $params = ['inst' => $installation->value];
        $in = [];
        foreach (array_values($productIds) as $i => $id) {
            $in[] = ':p' . $i;
            $params['p' . $i] = $id;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, ml_product_id, affiliate_url, source, confirmed_at FROM affiliate_links
             WHERE installation_id = :inst AND status = \'active\' AND ml_product_id IN (' . implode(', ', $in) . ')'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['ml_product_id']] = new AffiliateLink((int) $r['id'], (string) $r['ml_product_id'], (string) $r['affiliate_url'], (string) $r['source'], self::date((string) $r['confirmed_at']));
        }

        return $out;
    }

    public function openBatch(InstallationId $installation, \DateTimeImmutable $now): ?BatchView
    {
        $this->pdo->prepare(
            'UPDATE affiliate_link_batches SET status = \'expired\' WHERE installation_id = :inst AND status IN (\'exported\', \'preview\') AND expires_at <= :now'
        )->execute(['inst' => $installation->value, 'now' => self::ts($now)]);
        $stmt = $this->pdo->prepare(
            'SELECT public_key FROM affiliate_link_batches WHERE installation_id = :inst AND status IN (\'exported\', \'preview\') ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['inst' => $installation->value]);
        $key = $stmt->fetchColumn();

        return is_string($key) ? $this->batch($installation, $key) : null;
    }

    public function batch(InstallationId $installation, string $key): ?BatchView
    {
        $stmt = $this->pdo->prepare('SELECT * FROM affiliate_link_batches WHERE installation_id = :inst AND public_key = :key');
        $stmt->execute(['inst' => $installation->value, 'key' => $key]);
        $batch = $stmt->fetch();
        if (!is_array($batch)) {
            return null;
        }
        $items = $this->pdo->prepare(
            'SELECT i.*, COALESCE(p.name, i.ml_product_id) AS product_name FROM affiliate_link_batch_items i
             LEFT JOIN ml_products p ON p.ml_product_id = i.ml_product_id
             WHERE i.installation_id = :inst AND i.batch_id = :batch ORDER BY i.position'
        );
        $items->execute(['inst' => $installation->value, 'batch' => $batch['id']]);
        $lines = $this->pdo->prepare('SELECT * FROM affiliate_link_batch_lines WHERE installation_id = :inst AND batch_id = :batch ORDER BY line_no');
        $lines->execute(['inst' => $installation->value, 'batch' => $batch['id']]);

        return new BatchView(
            (int) $batch['id'],
            (string) $batch['public_key'],
            (string) $batch['status'],
            array_values(array_map(static fn (array $r): ExportedItem => new ExportedItem(
                (int) $r['id'], (string) $r['item_key'], (int) $r['position'], (string) $r['ml_product_id'], (string) $r['original_url'],
                (string) $r['product_name'], (string) $r['match_status'], $r['received_raw'] === null ? null : (string) $r['received_raw'],
            ), $items->fetchAll())),
            array_values(array_map(static fn (array $r): ReceivedLine => new ReceivedLine(
                (int) $r['line_no'], (string) $r['received_raw'], trim((string) $r['received_raw']), (string) $r['format_status'],
                $r['detected_product_id'] === null ? null : (string) $r['detected_product_id'], (string) $r['evidence'],
                $r['proposed_position'] === null ? null : (int) $r['proposed_position'],
            ), $lines->fetchAll())),
            $batch['anomalies'] === null || $batch['anomalies'] === '' ? [] : explode(',', (string) $batch['anomalies']),
            self::date((string) $batch['exported_at']),
            self::date((string) $batch['expires_at']),
        );
    }

    public function createBatch(InstallationId $installation, int $userId, array $products, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): BatchView
    {
        $key = bin2hex(random_bytes(10));
        $this->transaction(function () use ($installation, $userId, $products, $now, $expiresAt, $key): void {
            $this->pdo->prepare(
                'INSERT INTO affiliate_link_batches (installation_id, public_key, created_by_user_id, status, item_count, exported_at, expires_at)
                 VALUES (:inst, :key, :user, \'exported\', :count, :at, :exp)'
            )->execute(['inst' => $installation->value, 'key' => $key, 'user' => $userId, 'count' => count($products), 'at' => self::ts($now), 'exp' => self::ts($expiresAt)]);
            $batchId = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare(
                'INSERT INTO affiliate_link_batch_items (installation_id, batch_id, item_key, position, ml_product_id, original_url)
                 VALUES (:inst, :batch, :key, :pos, :product, :url)'
            );
            foreach (array_values($products) as $i => $product) {
                $insert->execute([
                    'inst' => $installation->value, 'batch' => $batchId, 'key' => bin2hex(random_bytes(10)), 'pos' => $i + 1,
                    'product' => $product->productId, 'url' => $product->originalUrl,
                ]);
            }
        });

        return $this->batch($installation, $key) ?? throw new \RuntimeException('Lote não gravado.');
    }

    public function savePreview(InstallationId $installation, int $batchId, BatchPreview $preview, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($installation, $batchId, $preview, $now): void {
            $scope = ['inst' => $installation->value, 'batch' => $batchId];
            $this->pdo->prepare('DELETE FROM affiliate_link_batch_lines WHERE installation_id = :inst AND batch_id = :batch')->execute($scope);
            $this->pdo->prepare(
                'UPDATE affiliate_link_batch_items SET received_raw = NULL, received_position = NULL, format_status = NULL,
                    match_evidence = \'none\', match_status = \'unmatched\', reason = NULL
                 WHERE installation_id = :inst AND batch_id = :batch AND match_status <> \'confirmed\''
            )->execute($scope);
            $line = $this->pdo->prepare(
                'INSERT INTO affiliate_link_batch_lines (installation_id, batch_id, line_no, received_raw, format_status, detected_product_id, evidence, proposed_position)
                 VALUES (:inst, :batch, :no, :raw, :format, :detected, :evidence, :proposed)'
            );
            $propose = $this->pdo->prepare(
                'UPDATE affiliate_link_batch_items SET received_raw = :raw, received_position = :no, format_status = :format,
                    match_evidence = :evidence, match_status = \'proposed\'
                 WHERE installation_id = :inst AND batch_id = :batch AND position = :pos AND match_status <> \'confirmed\''
            );
            foreach ($preview->lines as $received) {
                $line->execute($scope + [
                    'no' => $received->lineNo, 'raw' => $received->raw, 'format' => $received->formatStatus,
                    'detected' => $received->detectedProductId, 'evidence' => $received->evidence, 'proposed' => $received->proposedPosition,
                ]);
                if ($received->proposedPosition !== null) {
                    $propose->execute($scope + [
                        'raw' => $received->raw, 'no' => $received->lineNo, 'format' => $received->formatStatus,
                        'evidence' => $received->evidence, 'pos' => $received->proposedPosition,
                    ]);
                }
            }
            $this->pdo->prepare(
                'UPDATE affiliate_link_batches SET status = \'preview\', received_count = :received, anomalies = :anomalies, pasted_at = :at
                 WHERE installation_id = :inst AND id = :batch'
            )->execute($scope + ['received' => count($preview->lines), 'anomalies' => $preview->anomalies === [] ? null : implode(',', $preview->anomalies), 'at' => self::ts($now)]);
        });
    }

    public function confirm(InstallationId $installation, int $batchId, array $associations, int $userId, \DateTimeImmutable $now): ImportReport
    {
        $created = $replaced = $reused = 0;
        $this->transaction(function () use ($installation, $batchId, $associations, $userId, $now, &$created, &$replaced, &$reused): void {
            $scope = ['inst' => $installation->value, 'batch' => $batchId];
            $batch = $this->pdo->prepare('SELECT pasted_at FROM affiliate_link_batches WHERE installation_id = :inst AND id = :batch FOR UPDATE');
            $batch->execute($scope);
            $receivedAt = $batch->fetchColumn();
            if (!is_string($receivedAt)) {
                throw new \RuntimeException('Lote não pertence a esta conta.');
            }
            foreach ($associations as $association) {
                $item = $this->pdo->prepare('SELECT id, ml_product_id, original_url FROM affiliate_link_batch_items WHERE installation_id = :inst AND batch_id = :batch AND id = :id FOR UPDATE');
                $item->execute($scope + ['id' => $association['item_id']]);
                $itemRow = $item->fetch();
                $line = $this->pdo->prepare('SELECT received_raw FROM affiliate_link_batch_lines WHERE installation_id = :inst AND batch_id = :batch AND line_no = :no');
                $line->execute($scope + ['no' => $association['line_no']]);
                $raw = $line->fetchColumn();
                if (!is_array($itemRow) || !is_string($raw)) {
                    throw new \RuntimeException('Associação fora do lote desta conta.');
                }
                // O link é exatamente o texto recebido, sem os espaços em volta da linha. Nenhuma outra alteração.
                $url = trim($raw);
                $sha = hash('sha256', $url);

                $current = $this->pdo->prepare('SELECT id, affiliate_url_sha256 FROM affiliate_links WHERE installation_id = :inst AND active_product_id = :product FOR UPDATE');
                $current->execute(['inst' => $installation->value, 'product' => $itemRow['ml_product_id']]);
                $existing = $current->fetch();
                if (is_array($existing) && hash_equals((string) $existing['affiliate_url_sha256'], $sha)) {
                    $linkId = (int) $existing['id'];
                    $reused++;
                } else {
                    if (is_array($existing)) {
                        $this->pdo->prepare('UPDATE affiliate_links SET status = \'replaced\', replaced_at = :at, updated_at = :at2 WHERE installation_id = :inst AND id = :id')
                            ->execute(['at' => self::ts($now), 'at2' => self::ts($now), 'inst' => $installation->value, 'id' => $existing['id']]);
                        $replaced++;
                    }
                    $this->pdo->prepare(
                        'INSERT INTO affiliate_links
                            (installation_id, site_id, ml_product_id, original_url, affiliate_url, affiliate_url_sha256, source, batch_id, batch_item_id,
                             status, received_at, confirmed_at, confirmed_by_user_id, created_at, updated_at)
                         VALUES (:inst, \'MLB\', :product, :original, :url, :sha, \'manual_batch\', :batch, :item, \'active\', :received, :at, :user, :at2, :at3)'
                    )->execute([
                        'inst' => $installation->value, 'product' => $itemRow['ml_product_id'], 'original' => $itemRow['original_url'],
                        'url' => $url, 'sha' => $sha, 'batch' => $batchId, 'item' => $itemRow['id'], 'received' => $receivedAt,
                        'at' => self::ts($now), 'user' => $userId, 'at2' => self::ts($now), 'at3' => self::ts($now),
                    ]);
                    $linkId = (int) $this->pdo->lastInsertId();
                    $created++;
                }
                $this->pdo->prepare(
                    'UPDATE affiliate_link_batch_items SET received_raw = :raw, received_position = :no, format_status = \'valid\',
                        match_evidence = :evidence, match_status = \'confirmed\', confirmed_at = :at, affiliate_link_id = :link, reason = NULL
                     WHERE installation_id = :inst AND batch_id = :batch AND id = :id'
                )->execute($scope + ['raw' => $raw, 'no' => $association['line_no'], 'evidence' => $association['evidence'], 'at' => self::ts($now), 'link' => $linkId, 'id' => $itemRow['id']]);
            }
            // Itens não escolhidos continuam aguardando link (a proposta não confirmada é descartada).
            $this->pdo->prepare(
                'UPDATE affiliate_link_batch_items SET match_status = \'unmatched\', received_raw = NULL, received_position = NULL, format_status = NULL, match_evidence = \'none\'
                 WHERE installation_id = :inst AND batch_id = :batch AND match_status <> \'confirmed\''
            )->execute($scope);
            $counts = $this->pdo->prepare('SELECT COUNT(*) AS total, SUM(match_status = \'confirmed\') AS confirmed FROM affiliate_link_batch_items WHERE installation_id = :inst AND batch_id = :batch');
            $counts->execute($scope);
            $c = $counts->fetch();
            $confirmed = (int) ($c['confirmed'] ?? 0);
            $this->pdo->prepare(
                'UPDATE affiliate_link_batches SET status = :status, confirmed_count = :confirmed, confirmed_at = :at, confirmed_by_user_id = :user
                 WHERE installation_id = :inst AND id = :batch'
            )->execute($scope + ['status' => $confirmed === (int) $c['total'] ? 'confirmed' : 'partially_confirmed', 'confirmed' => $confirmed, 'at' => self::ts($now), 'user' => $userId]);
        });

        $left = $this->pdo->prepare('SELECT COUNT(*) FROM affiliate_link_batch_items WHERE installation_id = :inst AND batch_id = :batch AND match_status <> \'confirmed\'');
        $left->execute(['inst' => $installation->value, 'batch' => $batchId]);

        return new ImportReport($created, $replaced, $reused, (int) $left->fetchColumn());
    }

    public function cancelBatch(InstallationId $installation, int $batchId): void
    {
        $this->pdo->prepare('UPDATE affiliate_link_batches SET status = \'cancelled\' WHERE installation_id = :inst AND id = :batch AND status IN (\'exported\', \'preview\')')
            ->execute(['inst' => $installation->value, 'batch' => $batchId]);
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

    private static function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private static function ts(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
