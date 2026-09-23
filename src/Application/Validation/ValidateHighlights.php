<?php

declare(strict_types=1);

namespace Sinergia\Application\Validation;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Application\Port\MercadoLivre\HighlightEntry;
use Sinergia\Application\Port\MercadoLivre\HighlightReader;
use Sinergia\Application\Port\MercadoLivre\MercadoLivreFailure;
use Sinergia\Application\Port\Persistence\DiscoveryRunLog;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Id\CorrelationId;

/**
 * Validação prática (somente leitura) do ranking oficial de uma categoria.
 * Cada execução vira um discovery_run identificável + evidência sanitizada.
 * Posição é RANKING; este caso de uso nunca produz "quantidade vendida".
 */
final class ValidateHighlights
{
    public const string SOURCE = 'highlights_validation';

    public function __construct(
        private readonly HighlightReader $highlights,
        private readonly DiscoveryRunLog $runs,
        private readonly EvidenceWriter $evidence,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        InstallationId $installation,
        string $siteId,
        string $categoryId,
        AuthMode $auth,
        ?string $attribute = null,
        ?string $attributeValue = null,
        ?string $categoryName = null,
    ): ValidationOutcome {
        $correlationId = CorrelationId::generate();
        $startedAt = $this->clock->now();
        $runId = $this->runs->start(
            $installation, $correlationId, self::SOURCE, $siteId, $categoryId, $auth->value, $startedAt, $attribute, $attributeValue,
        );
        $request = [
            'method' => 'GET',
            'path' => sprintf('/highlights/%s/category/%s', $siteId, $categoryId),
            'query' => $attribute === null ? [] : ['attribute' => $attribute, 'attributeValue' => $attributeValue],
            'auth' => $auth === AuthMode::Required ? 'Bearer (OAuth da instalação; valor nunca registrado)' : 'nenhuma',
        ];

        try {
            $result = $this->highlights->topByCategory($siteId, $categoryId, $auth, $attribute, $attributeValue);
        } catch (MercadoLivreFailure $e) {
            return $this->fail($installation, $runId, $correlationId, $request, $categoryId, $categoryName, $e);
        }

        $meta = $result->meta;
        $this->runs->addEntries($installation, $runId, $result->entries);
        $summary = [
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'items_found' => $result->count(),
            'highlight_type' => $result->highlightType,
            'criteria' => $result->criteria,
            'query_id' => $result->queryId,
            'types' => $result->countByType(),
            'entries' => array_map(static fn (HighlightEntry $e): array => $e->toArray(), $result->entries),
            'warnings' => $result->warnings,
        ];
        $file = $this->evidence->write(sprintf('run-%d-highlights-%s', $runId, $categoryId), [
            'correlation_id' => $correlationId,
            'discovery_run_id' => $runId,
            'captured_at' => $meta->fetchedAt->format(DATE_ATOM),
            'request' => $request,
            'response' => [
                'http_status' => $meta->status,
                'duration_ms' => $meta->durationMs,
                'headers' => $meta->headers,
                'rate_limit' => $meta->rateLimit,
                'top_level_keys' => array_keys($meta->body),
                'body' => $meta->body,
            ],
            'parsed' => $summary,
            'note' => 'Posição = ranking oficial da categoria. Não representa quantidade vendida.',
        ]);

        $this->runs->finish(
            $installation, $runId, 'succeeded', $this->clock->now(), $meta->status, $result->count(), $meta->durationMs,
            rateLimit: $meta->rateLimit, headers: $meta->headers, warnings: $result->warnings, evidenceFile: basename($file),
        );
        $this->logger->info('validation.highlights.succeeded', [
            'discovery_run_id' => $runId, 'correlation_id' => $correlationId, 'category_id' => $categoryId,
            'count_processed' => $result->count(), 'http_status' => $meta->status, 'duration_ms' => $meta->durationMs,
        ]);

        return new ValidationOutcome($runId, $correlationId, true, $meta->status, null, null, $file, $summary);
    }

    /** @param array<string, mixed> $request */
    private function fail(
        InstallationId $installation,
        int $runId,
        string $correlationId,
        array $request,
        string $categoryId,
        ?string $categoryName,
        MercadoLivreFailure $e,
    ): ValidationOutcome {
        $summary = ['category_id' => $categoryId, 'category_name' => $categoryName, 'items_found' => 0];
        $file = $this->evidence->write(sprintf('run-%d-highlights-%s-error', $runId, $categoryId), [
            'correlation_id' => $correlationId,
            'discovery_run_id' => $runId,
            'captured_at' => $this->clock->now()->format(DATE_ATOM),
            'request' => $request,
            'error' => [
                'class' => (new \ReflectionClass($e))->getShortName(),
                'error_code' => $e->failureCode(),
                'ml_error' => $e->failureMlError(),
                'http_status' => $e->failureHttpStatus(),
                'message' => $e->getMessage(),
                'headers' => $e->failureHeaders(),
                'rate_limit' => $e->failureRateLimit(),
                'duration_ms' => $e->failureDurationMs(),
            ],
        ]);
        $code = $e->failureMlError() ?? $e->failureCode();
        $this->runs->finish(
            $installation, $runId, 'failed', $this->clock->now(), $e->failureHttpStatus(), null, $e->failureDurationMs(),
            errorCode: $code, errorMessage: $e->getMessage(),
            rateLimit: $e->failureRateLimit(), headers: $e->failureHeaders(), evidenceFile: basename($file),
        );
        $this->logger->warning('validation.highlights.failed', [
            'discovery_run_id' => $runId, 'correlation_id' => $correlationId, 'category_id' => $categoryId,
            'error_code' => $e->failureCode(), 'http_status' => $e->failureHttpStatus(),
        ]);

        return new ValidationOutcome($runId, $correlationId, false, $e->failureHttpStatus(), $code, $e->getMessage(), $file, $summary);
    }
}
