<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Niche\NicheFilters;
use Sinergia\Application\Offer\OfferCandidate;
use Sinergia\Application\Offer\SelectionTarget;
use Sinergia\Application\Port\Offer\OfferSelectionStore;
use Sinergia\Domain\Installation\InstallationId;

/** Dados privados da seleção. TODA consulta e escrita filtra por installation_id. */
final class OfferSelectionRepository implements OfferSelectionStore
{
    /** Execuções mantidas por conta (as mais antigas e seus candidatos são apagados). */
    public const int RUNS_KEPT = 10;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function targets(InstallationId $installation, string $siteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT an.niche_id, n.sort AS niche_sort, s.id AS subniche_id, s.sort AS subniche_sort,
                    c.ml_category_id, c.expected_domains,
                    an.min_discount_pct, an.min_price, an.max_price, an.require_photo
             FROM account_subniches a
             JOIN account_niches an ON an.installation_id = a.installation_id AND an.niche_id = a.niche_id
             JOIN niches n ON n.id = an.niche_id AND n.active = 1
             JOIN subniches s ON s.id = a.subniche_id AND s.niche_id = a.niche_id AND s.active = 1
             JOIN subniche_categories c ON c.subniche_id = s.id AND c.site_id = :site AND c.status = \'approved\'
             WHERE a.installation_id = :inst
             ORDER BY n.sort, n.id, s.sort, s.id, c.ml_category_id'
        );
        $stmt->execute(['inst' => $installation->value, 'site' => $siteId]);

        $targets = [];
        foreach ($stmt->fetchAll() as $row) {
            $targets[] = new SelectionTarget(
                (int) $row['niche_id'],
                (int) $row['niche_sort'],
                (int) $row['subniche_id'],
                (int) $row['subniche_sort'],
                (string) $row['ml_category_id'],
                array_values(array_filter(array_map('trim', explode(',', (string) $row['expected_domains'])), static fn (string $d): bool => $d !== '')),
                new NicheFilters(
                    $row['min_discount_pct'] === null ? null : (int) $row['min_discount_pct'],
                    $row['min_price'] === null ? null : PublicCatalogCacheRepository::cents((string) $row['min_price']),
                    $row['max_price'] === null ? null : PublicCatalogCacheRepository::cents((string) $row['max_price']),
                    (bool) $row['require_photo'],
                ),
            );
        }

        return $targets;
    }

    public function startRun(InstallationId $installation, \DateTimeImmutable $now): int
    {
        $this->pdo->prepare('INSERT INTO offer_selection_runs (installation_id, started_at) VALUES (:inst, :at)')
            ->execute(['inst' => $installation->value, 'at' => self::ts($now)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function finishRun(
        InstallationId $installation,
        int $runId,
        string $status,
        int $apiCalls,
        array $stats,
        array $candidates,
        \DateTimeImmutable $now,
    ): void {
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare(
                'UPDATE offer_selection_runs SET status = :status, api_calls = :calls, stats_json = :stats, finished_at = :at
                 WHERE installation_id = :inst AND id = :run'
            );
            $update->execute([
                'status' => $status,
                'calls' => min($apiCalls, 65535),
                'stats' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'at' => self::ts($now),
                'inst' => $installation->value,
                'run' => $runId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Execução de seleção não pertence a esta conta.');
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO account_offer_candidates
                    (installation_id, run_id, ml_product_id, niche_id, subniche_id, ml_category_id, ranking_position, sort_order,
                     item_id, price, original_price, discount_pct, selection_rule)
                 VALUES (:inst, :run, :product, :niche, :sub, :cat, :pos, :ord, :item, :price, :original, :discount, :rule)'
            );
            foreach (array_values($candidates) as $order => $candidate) {
                /** @var OfferCandidate $candidate */
                $insert->execute([
                    'inst' => $installation->value,
                    'run' => $runId,
                    'product' => $candidate->product->id,
                    'niche' => $candidate->target->nicheId,
                    'sub' => $candidate->target->subnicheId,
                    'cat' => $candidate->target->categoryId,
                    'pos' => $candidate->rankingPosition,
                    'ord' => $order + 1,
                    'item' => $candidate->offer->itemId,
                    'price' => PublicCatalogCacheRepository::decimal($candidate->offer->priceCents),
                    'original' => PublicCatalogCacheRepository::decimal($candidate->offer->originalPriceCents),
                    'discount' => $candidate->offer->discountPct,
                    'rule' => $candidate->offer->rule,
                ]);
            }

            // Retenção: só as últimas RUNS_KEPT execuções desta conta.
            $old = $this->pdo->prepare(
                'SELECT id FROM offer_selection_runs WHERE installation_id = :inst ORDER BY id DESC LIMIT 1000 OFFSET ' . self::RUNS_KEPT
            );
            $old->execute(['inst' => $installation->value]);
            $delete = $this->pdo->prepare('DELETE FROM offer_selection_runs WHERE installation_id = :inst AND id = :id');
            foreach ($old->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $delete->execute(['inst' => $installation->value, 'id' => (int) $id]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Candidatos da última execução concluída da conta (para exibição/CLI), com dados públicos do produto.
     *
     * @return list<array{position: int, product_id: string, name: string, niche: string, subniche: string, price_cents: int, original_cents: ?int, discount_pct: int, rule: string, permalink: string, has_photo: bool}>
     */
    public function latestCandidates(InstallationId $installation): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.sort_order, c.ml_product_id, p.name, n.name AS niche, s.name AS subniche, c.price, c.original_price,
                    c.discount_pct, c.selection_rule, p.permalink, p.picture_url
             FROM account_offer_candidates c
             JOIN offer_selection_runs r ON r.installation_id = c.installation_id AND r.id = c.run_id
             JOIN ml_products p ON p.ml_product_id = c.ml_product_id
             JOIN subniches s ON s.id = c.subniche_id
             JOIN niches n ON n.id = c.niche_id
             WHERE c.installation_id = :inst
               AND c.run_id = (SELECT MAX(id) FROM offer_selection_runs WHERE installation_id = :inst2 AND status IN (\'completed\', \'partial\'))
             ORDER BY c.sort_order'
        );
        $stmt->execute(['inst' => $installation->value, 'inst2' => $installation->value]);

        return array_values(array_map(static fn (array $row): array => [
            'position' => (int) $row['sort_order'],
            'product_id' => (string) $row['ml_product_id'],
            'name' => (string) $row['name'],
            'niche' => (string) $row['niche'],
            'subniche' => (string) $row['subniche'],
            'price_cents' => PublicCatalogCacheRepository::cents((string) $row['price']),
            'original_cents' => $row['original_price'] === null ? null : PublicCatalogCacheRepository::cents((string) $row['original_price']),
            'discount_pct' => (int) $row['discount_pct'],
            'rule' => (string) $row['selection_rule'],
            'permalink' => (string) $row['permalink'],
            'has_photo' => $row['picture_url'] !== null,
        ], $stmt->fetchAll()));
    }

    private static function ts(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
