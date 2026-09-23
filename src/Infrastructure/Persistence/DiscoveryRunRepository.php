<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Port\MercadoLivre\HighlightEntry;
use Sinergia\Application\Port\Persistence\DiscoveryRunLog;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Security\Redactor;

/** Implementação MariaDB de DiscoveryRunLog (validação de categoria ou de highlights). */
final class DiscoveryRunRepository implements DiscoveryRunLog
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function start(
        InstallationId $installation,
        string $correlationId,
        string $source,
        string $siteId,
        ?string $categoryId,
        string $authMode,
        \DateTimeImmutable $startedAt,
        ?string $filterAttribute = null,
        ?string $filterValue = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discovery_runs
                (installation_id, correlation_id, source, site_id, category_id, filter_attribute, filter_value, auth_mode, status, started_at)
             VALUES (:inst, :corr, :source, :site, :cat, :fa, :fv, :auth, \'running\', :started)'
        );
        $stmt->execute([
            'inst' => $installation->value,
            'corr' => $correlationId,
            'source' => $source,
            'site' => $siteId,
            'cat' => $categoryId,
            'fa' => $filterAttribute,
            'fv' => $filterValue,
            'auth' => $authMode,
            'started' => $startedAt->format('Y-m-d H:i:s.v'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed>|null $rateLimit
     * @param array<string, string>|null $headers
     * @param list<string> $warnings
     */
    public function finish(
        InstallationId $installation,
        int $runId,
        string $status,
        \DateTimeImmutable $finishedAt,
        ?int $httpStatus,
        ?int $itemsFound,
        ?int $durationMs,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?array $rateLimit = null,
        ?array $headers = null,
        array $warnings = [],
        ?string $evidenceFile = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE discovery_runs SET
                status = :status, finished_at = :finished, http_status = :http, items_found = :items,
                duration_ms = :duration, error_code = :ecode, error_message = :emsg,
                rate_limit_json = :rl, response_headers_json = :headers, warnings_json = :warnings, evidence_file = :evidence
             WHERE installation_id = :inst AND id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'finished' => $finishedAt->format('Y-m-d H:i:s.v'),
            'http' => $httpStatus,
            'items' => $itemsFound,
            'duration' => $durationMs,
            'ecode' => $errorCode === null ? null : mb_substr($errorCode, 0, 64),
            'emsg' => $errorMessage === null ? null : mb_substr(Redactor::redactText($errorMessage), 0, 500),
            'rl' => $rateLimit === null ? null : json_encode($rateLimit, JSON_THROW_ON_ERROR),
            'headers' => $headers === null ? null : json_encode(Redactor::redactArray($headers), JSON_THROW_ON_ERROR),
            'warnings' => $warnings === [] ? null : json_encode($warnings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'evidence' => $evidenceFile,
            'inst' => $installation->value,
            'id' => $runId,
        ]);
    }

    /** @param list<HighlightEntry> $entries */
    public function addEntries(InstallationId $installation, int $runId, array $entries): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discovery_run_entries (installation_id, discovery_run_id, position, ml_entity_id, ml_entity_type)
             VALUES (:inst, :run, :pos, :id, :type)'
        );
        foreach ($entries as $entry) {
            $stmt->execute([
                'inst' => $installation->value,
                'run' => $runId,
                'pos' => $entry->position,
                'id' => $entry->id,
                'type' => $entry->type,
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    public function find(InstallationId $installation, int $runId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discovery_runs WHERE installation_id = :inst AND id = :id');
        $stmt->execute(['inst' => $installation->value, 'id' => $runId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function entries(InstallationId $installation, int $runId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT position, ml_entity_id, ml_entity_type FROM discovery_run_entries
             WHERE installation_id = :inst AND discovery_run_id = :run ORDER BY position, id'
        );
        $stmt->execute(['inst' => $installation->value, 'run' => $runId]);

        return array_values($stmt->fetchAll());
    }
}
