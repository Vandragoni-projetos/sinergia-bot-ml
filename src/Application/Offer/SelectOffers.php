<?php

declare(strict_types=1);

namespace Sinergia\Application\Offer;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Port\MercadoLivre\HighlightEntry;
use Sinergia\Application\Port\MercadoLivre\MercadoLivreFailure;
use Sinergia\Application\Port\Offer\CatalogSource;
use Sinergia\Application\Port\Offer\CatalogSourceFactory;
use Sinergia\Application\Port\Offer\OfferSelectionStore;
use Sinergia\Application\Port\Offer\PublicCatalogCache;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Clock\Clock;

/**
 * Seletor de ofertas (F1, etapa 4): escolhas da conta → categorias aprovadas → ranking oficial
 * → só produtos de catálogo MLB… → sem duplicados → domínio esperado → foto/permalink → oferta → filtros.
 *
 * Regra completa em docs/seletor-de-ofertas.md. Resumo da deduplicação: cada produto é avaliado uma vez
 * por execução; as ocorrências são percorridas na ordem (posição no ranking, nicho, subnicho, categoria) e o
 * produto é atribuído à PRIMEIRA ocorrência cujas regras ele cumpre. Se nenhuma cumprir, o motivo registrado é
 * o da primeira ocorrência.
 */
final class SelectOffers
{
    public const int RANKING_TTL_SECONDS = 6 * 3600;
    public const int RANKING_STALE_MAX_SECONDS = 48 * 3600;
    public const int PRODUCT_TTL_SECONDS = 3600;
    public const int PRODUCT_STALE_MAX_SECONDS = 7 * 86400;
    public const int OFFER_TTL_SECONDS = 3600;
    public const int NEGATIVE_TTL_SECONDS = 24 * 3600;
    public const int MAX_API_CALLS_PER_RUN = 300;
    public const int LOW_VOLUME_THRESHOLD = 8;

    private CatalogSource $source;
    private \DateTimeImmutable $now;
    private int $calls = 0;
    private bool $rateLimited = false;
    private bool $degraded = false;
    /** @var array<string, mixed> */
    private array $stats = [];

    public function __construct(
        private readonly OfferSelectionStore $store,
        private readonly PublicCatalogCache $cache,
        private readonly CatalogSourceFactory $sources,
        private readonly OfferChooser $chooser,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $siteId,
    ) {
    }

    public function run(InstallationId $installation): SelectionReport
    {
        $this->now = $this->clock->now();
        $this->calls = 0;
        $this->rateLimited = false;
        $this->degraded = false;
        $this->stats = [
            'categories' => 0, 'categories_from_cache' => 0, 'categories_stale' => 0, 'categories_failed' => [],
            'low_volume_categories' => [], 'ranking_entries' => 0, 'duplicates' => 0, 'products_evaluated' => 0,
            'rejections' => [], 'accepted' => 0, 'rate_limited' => false, 'budget_exhausted' => false,
        ];

        $targets = $this->store->targets($installation, $this->siteId);
        $runId = $this->store->startRun($installation, $this->now);
        if ($targets === []) {
            return $this->finish($installation, $runId, SelectionReport::NO_SELECTION, []);
        }

        $this->source = $this->sources->forInstallation($installation);
        try {
            $candidates = $this->select($targets);
        } catch (SelectionAborted) {
            return $this->finish($installation, $runId, SelectionReport::AUTH_FAILED, []);
        }

        return $this->finish($installation, $runId, $this->statusAfterSelection(), $candidates);
    }

    /**
     * @param list<SelectionTarget> $targets
     *
     * @return list<OfferCandidate>
     */
    private function select(array $targets): array
    {
        // 1) Rankings das categorias aprovadas → ocorrências de produtos de catálogo.
        $occurrences = [];
        foreach ($targets as $target) {
            $this->stats['categories']++;
            $entries = $this->ranking($target->categoryId);
            if ($entries === null) {
                continue;
            }
            $catalog = 0;
            foreach ($entries as $entry) {
                $this->stats['ranking_entries']++;
                if (!self::isCatalogProduct($entry)) {
                    $this->reject('not_catalog_product');
                    continue;
                }
                $catalog++;
                $occurrences[] = [$entry->position, $target, $entry->id];
            }
            if ($catalog < self::LOW_VOLUME_THRESHOLD) {
                $this->stats['low_volume_categories'][] = $target->categoryId;
            }
        }

        // 2) Ordem determinística e agrupamento por ml_product_id (deduplicação).
        usort($occurrences, static fn (array $a, array $b): int => [$a[0], ...$a[1]->orderKey(), $a[2]] <=> [$b[0], ...$b[1]->orderKey(), $b[2]]);
        $byProduct = [];
        foreach ($occurrences as [$position, $target, $productId]) {
            $byProduct[$productId][] = [$position, $target];
        }
        $this->stats['duplicates'] = count($occurrences) - count($byProduct);

        // 3) Avaliação de cada produto uma única vez.
        $candidates = [];
        foreach ($byProduct as $productId => $list) {
            $this->stats['products_evaluated']++;
            [$product, $fromApi] = $this->product((string) $productId);
            if ($product === null) {
                continue;
            }

            $offer = null;
            $offerReason = null;
            $offerLoaded = false;
            $firstReason = null;
            foreach ($list as [$position, $target]) {
                $reason = self::productReason($product, $target);
                if ($reason === null) {
                    if (!$offerLoaded) {
                        [$offer, $offerReason] = $this->offer($product, $fromApi);
                        $offerLoaded = true;
                    }
                    $reason = $offer === null ? $offerReason : self::filterReason($offer, $target);
                }
                if ($reason === null && $offer !== null) {
                    $candidates[] = new OfferCandidate($product, $offer, $target, $position);
                    $firstReason = null;
                    break;
                }
                $firstReason ??= $reason;
            }
            if ($firstReason !== null) {
                $this->reject($firstReason);
            }
        }

        usort($candidates, static fn (OfferCandidate $a, OfferCandidate $b): int => [$a->rankingPosition, ...$a->target->orderKey(), $a->product->id]
            <=> [$b->rankingPosition, ...$b->target->orderKey(), $b->product->id]);
        $this->stats['accepted'] = count($candidates);

        return $candidates;
    }

    /** @return list<HighlightEntry>|null */
    private function ranking(string $categoryId): ?array
    {
        $cached = $this->cache->ranking($this->siteId, $categoryId);
        $age = $cached === null ? null : $this->age($cached['fetched_at']);
        if ($cached !== null && $age < self::RANKING_TTL_SECONDS) {
            $this->stats['categories_from_cache']++;

            return $cached['entries'];
        }

        $failure = 'skipped_no_budget';
        if ($this->canCall()) {
            try {
                $entries = $this->call(fn () => $this->source->ranking($this->siteId, $categoryId));
                $this->cache->saveRanking($this->siteId, $categoryId, $entries, $this->now);

                return $entries;
            } catch (MercadoLivreFailure $e) {
                $failure = $e->failureCode();
            }
        }

        if ($cached !== null && $age < self::RANKING_STALE_MAX_SECONDS) {
            $this->stats['categories_stale']++;
            $this->degraded = true;

            return $cached['entries'];
        }
        $this->degraded = true;
        $this->stats['categories_failed'][$failure] = ($this->stats['categories_failed'][$failure] ?? 0) + 1;

        return null;
    }

    /** @return array{0: ?CatalogProduct, 1: bool} produto e se veio da API NESTA execução (buy_box_winner atual) */
    private function product(string $productId): array
    {
        $cached = $this->cache->product($productId);
        $age = $cached === null ? null : $this->age($cached['fetched_at']);
        if ($cached !== null && $cached['status'] === PublicCatalogCache::STATUS_OK && $age < self::PRODUCT_TTL_SECONDS) {
            return [$cached['product'], false];
        }
        if ($cached !== null && $cached['status'] !== PublicCatalogCache::STATUS_OK && $age < self::NEGATIVE_TTL_SECONDS) {
            $this->reject('product_unavailable');

            return [null, false];
        }

        if ($this->canCall()) {
            try {
                $product = $this->call(fn () => $this->source->product($productId));
                $this->cache->saveProduct($productId, $this->siteId, PublicCatalogCache::STATUS_OK, $product, $this->now);

                return [$product, true];
            } catch (MercadoLivreFailure $e) {
                $status = match ($e->failureHttpStatus()) {
                    404 => PublicCatalogCache::STATUS_NOT_FOUND,
                    403 => PublicCatalogCache::STATUS_FORBIDDEN,
                    default => null,
                };
                if ($status !== null) {
                    $this->cache->saveProduct($productId, $this->siteId, $status, null, $this->now);
                    $this->reject('product_unavailable');

                    return [null, false];
                }
            }
        }

        if ($cached !== null && $cached['status'] === PublicCatalogCache::STATUS_OK && $age < self::PRODUCT_STALE_MAX_SECONDS) {
            // Dados cadastrais antigos servem para avaliar domínio/foto; a oferta tentará consultar de novo.
            $this->degraded = true;

            return [$cached['product'], false];
        }
        $this->degraded = true;
        $this->reject('product_unavailable');

        return [null, false];
    }

    /** @return array{0: ?ChosenOffer, 1: ?string} oferta ou motivo de descarte */
    private function offer(CatalogProduct $product, bool $fromApi): array
    {
        $cached = $this->cache->offer($product->id);
        if ($cached !== null && $this->age($cached['fetched_at']) < self::OFFER_TTL_SECONDS) {
            return $cached['status'] === PublicCatalogCache::STATUS_OK ? [$cached['offer'], null] : [null, 'no_offer'];
        }
        // A oferta sempre parte de um /products/{id} desta execução (o buy_box_winner não é cacheado).
        if (!$fromApi) {
            if (!$this->canCall()) {
                $this->degraded = true;

                return [null, 'offer_unavailable'];
            }
            try {
                $product = $this->call(fn () => $this->source->product($product->id));
                $this->cache->saveProduct($product->id, $this->siteId, PublicCatalogCache::STATUS_OK, $product, $this->now);
            } catch (MercadoLivreFailure) {
                $this->degraded = true;

                return [null, 'offer_unavailable'];
            }
        }

        $chosen = $this->chooser->chooseFromBuyBox($product);
        if ($chosen === null) {
            if (!$this->canCall()) {
                $this->degraded = true;

                return [null, 'offer_unavailable'];
            }
            try {
                $result = $this->call(fn () => $this->source->offers($product->id));
                $chosen = $this->chooser->chooseFromItems($result['offers']);
                if ($chosen !== null && $result['total'] !== null && $result['total'] > count($result['offers'])) {
                    $this->stats['offers_paged'] = ($this->stats['offers_paged'] ?? 0) + 1;
                }
            } catch (MercadoLivreFailure $e) {
                if ($e->failureHttpStatus() !== 404) {
                    $this->degraded = true;

                    return [null, 'offer_unavailable'];
                }
            }
        }

        $this->cache->saveOffer($product->id, $chosen === null ? PublicCatalogCache::STATUS_NO_OFFER : PublicCatalogCache::STATUS_OK, $chosen, $this->now);

        return $chosen === null ? [null, 'no_offer'] : [$chosen, null];
    }

    private static function isCatalogProduct(HighlightEntry $entry): bool
    {
        return $entry->type === 'PRODUCT' && preg_match('/^MLB\d{1,20}$/', $entry->id) === 1;
    }

    private static function productReason(CatalogProduct $product, SelectionTarget $target): ?string
    {
        return match (true) {
            $product->catalogStatus !== null && $product->catalogStatus !== 'active' => 'product_inactive',
            $product->domainId === null || !in_array($product->domainId, $target->expectedDomains, true) => 'domain_mismatch',
            $product->permalink === null => 'no_permalink',
            $target->filters->requirePhoto && !$product->hasPhoto() => 'no_photo',
            default => null,
        };
    }

    private static function filterReason(ChosenOffer $offer, SelectionTarget $target): ?string
    {
        $filters = $target->filters;

        return match (true) {
            $filters->minPriceCents !== null && $offer->priceCents < $filters->minPriceCents => 'price_below_min',
            $filters->maxPriceCents !== null && $offer->priceCents > $filters->maxPriceCents => 'price_above_max',
            $filters->minDiscountPct !== null && $offer->discountPct < $filters->minDiscountPct => 'discount_below_min',
            default => null,
        };
    }

    private function statusAfterSelection(): string
    {
        return $this->degraded || $this->rateLimited || $this->stats['budget_exhausted'] === true
            ? SelectionReport::PARTIAL : SelectionReport::COMPLETED;
    }

    private function canCall(): bool
    {
        if ($this->calls >= self::MAX_API_CALLS_PER_RUN) {
            $this->stats['budget_exhausted'] = true;

            return false;
        }

        return !$this->rateLimited;
    }

    /**
     * @template T
     *
     * @param callable(): T $request
     *
     * @return T
     */
    private function call(callable $request): mixed
    {
        $this->calls++;
        try {
            return $request();
        } catch (MercadoLivreFailure $e) {
            // 401 ou credencial da conta ausente/irrecuperável: nada mais pode ser consultado com ela.
            if ($e->failureHttpStatus() === 401
                || in_array($e->failureCode(), ['oauth_not_connected', 'refresh_unavailable', 'credential_not_connected'], true)
                || $e->failureMlError() === 'invalid_grant') {
                $this->stats['auth_failure'] = $e->failureCode();
                throw new SelectionAborted();
            }
            if ($e->failureHttpStatus() === 429) {
                // Para de chamar a API nesta execução; o restante usa só o cache.
                $this->rateLimited = true;
                $this->stats['rate_limited'] = true;
            }
            throw $e;
        }
    }

    private function reject(string $reason): void
    {
        $this->stats['rejections'][$reason] = ($this->stats['rejections'][$reason] ?? 0) + 1;
    }

    private function age(\DateTimeImmutable $fetchedAt): int
    {
        return $this->now->getTimestamp() - $fetchedAt->getTimestamp();
    }

    /** @param list<OfferCandidate> $candidates */
    private function finish(InstallationId $installation, int $runId, string $status, array $candidates): SelectionReport
    {
        ksort($this->stats['rejections']);
        $this->store->finishRun($installation, $runId, $status, $this->calls, $this->stats, $candidates, $this->clock->now());
        $this->logger->info('offers.selection_finished', [
            'installation_id' => $installation->value,
            'run_id' => $runId,
            'status' => $status,
            'api_calls' => $this->calls,
            'accepted' => count($candidates),
        ]);

        return new SelectionReport($runId, $status, $this->calls, $this->stats, $candidates);
    }
}
