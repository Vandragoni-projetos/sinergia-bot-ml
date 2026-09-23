<?php

declare(strict_types=1);

namespace Sinergia\Application\Validation;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Application\Port\MercadoLivre\CategoryReader;
use Sinergia\Application\Port\MercadoLivre\CategoryRef;
use Sinergia\Application\Port\MercadoLivre\MercadoLivreFailure;
use Sinergia\Application\Port\MercadoLivre\ResponseMeta;
use Sinergia\Application\Port\Persistence\CategoryCache;
use Sinergia\Application\Port\Persistence\DiscoveryRunLog;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Id\CorrelationId;

/**
 * Validação prática (somente leitura) da árvore oficial: nome, pai, path_from_root,
 * filhos e domínio de catálogo. Atualiza o cache ml_categories e registra a execução.
 */
final class ValidateCategory
{
    public const string SOURCE = 'category_validation';

    public function __construct(
        private readonly CategoryReader $categories,
        private readonly CategoryCache $cache,
        private readonly DiscoveryRunLog $runs,
        private readonly EvidenceWriter $evidence,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(InstallationId $installation, string $siteId, ?string $categoryId, AuthMode $auth): ValidationOutcome
    {
        $correlationId = CorrelationId::generate();
        $runId = $this->runs->start($installation, $correlationId, self::SOURCE, $siteId, $categoryId, $auth->value, $this->clock->now());
        $label = $categoryId ?? 'roots';

        try {
            if ($categoryId === null) {
                $roots = $this->categories->siteRoots($siteId, $auth);
                $meta = $roots->meta;
                $summary = ['site_id' => $siteId, 'roots' => array_map(static fn (CategoryRef $r): array => $r->toArray(), $roots->roots)];
                $count = $roots->count();
            } else {
                $category = $this->categories->category($siteId, $categoryId, $auth);
                $meta = $category->meta;
                $this->cache->upsert($siteId, $category, $category->fetchedAt);
                $summary = [
                    'id' => $category->id,
                    'name' => $category->name,
                    'parent_id' => $category->parentId(),
                    'path_from_root' => $category->pathArray(),
                    'path_label' => $category->pathLabel(),
                    'children' => $category->childrenArray(),
                    'is_leaf' => $category->isLeaf(),
                    'catalog_domain' => $category->catalogDomain,
                    'total_items_in_this_category' => $category->totalItems,
                    'permalink' => $category->permalink,
                ];
                $count = count($category->children);
            }
        } catch (MercadoLivreFailure $e) {
            $file = $this->evidence->write(sprintf('run-%d-category-%s-error', $runId, $label), [
                'correlation_id' => $correlationId,
                'discovery_run_id' => $runId,
                'request' => ['auth' => $auth->value, 'category_id' => $categoryId, 'site_id' => $siteId],
                'error' => [
                    'error_code' => $e->failureCode(), 'ml_error' => $e->failureMlError(), 'http_status' => $e->failureHttpStatus(),
                    'message' => $e->getMessage(), 'headers' => $e->failureHeaders(), 'rate_limit' => $e->failureRateLimit(),
                ],
            ]);
            $code = $e->failureMlError() ?? $e->failureCode();
            $this->runs->finish(
                $installation, $runId, 'failed', $this->clock->now(), $e->failureHttpStatus(), null, $e->failureDurationMs(),
                errorCode: $code, errorMessage: $e->getMessage(),
                rateLimit: $e->failureRateLimit(), headers: $e->failureHeaders(), evidenceFile: basename($file),
            );
            $this->logger->warning('validation.category.failed', ['discovery_run_id' => $runId, 'error_code' => $e->failureCode()]);

            return new ValidationOutcome($runId, $correlationId, false, $e->failureHttpStatus(), $code, $e->getMessage(), $file, []);
        }

        $file = $this->evidence->write(sprintf('run-%d-category-%s', $runId, $label), [
            'correlation_id' => $correlationId,
            'discovery_run_id' => $runId,
            'captured_at' => $meta?->fetchedAt->format(DATE_ATOM),
            'request' => ['method' => 'GET', 'path' => $meta?->path, 'auth' => $auth->value],
            'response' => self::responseEvidence($meta),
            'parsed' => $summary,
        ]);
        $this->runs->finish(
            $installation, $runId, 'succeeded', $this->clock->now(), $meta?->status, $count, $meta?->durationMs,
            rateLimit: $meta?->rateLimit, headers: $meta?->headers, evidenceFile: basename($file),
        );

        return new ValidationOutcome($runId, $correlationId, true, $meta?->status, null, null, $file, $summary);
    }

    /** @return array<string, mixed> */
    private static function responseEvidence(?ResponseMeta $meta): array
    {
        if ($meta === null) {
            return ['note' => 'metadados de resposta indisponíveis'];
        }

        return [
            'http_status' => $meta->status,
            'duration_ms' => $meta->durationMs,
            'headers' => $meta->headers,
            'rate_limit' => $meta->rateLimit,
            'top_level_keys' => array_keys($meta->body),
            'body' => $meta->body,
        ];
    }
}
