<?php

declare(strict_types=1);

namespace Sinergia\Tests\Support;

use Sinergia\Application\Port\MercadoLivre\HighlightEntry;
use Sinergia\Application\Port\Persistence\DiscoveryRunLog;
use Sinergia\Domain\Installation\InstallationId;

/** DiscoveryRunLog em memória (testes sem banco). */
final class InMemoryDiscoveryRunLog implements DiscoveryRunLog
{
    /** @var array<int, array<string, mixed>> */
    public array $runs = [];

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
        $id = count($this->runs) + 1;
        $this->runs[$id] = [
            'installation_id' => $installation->value,
            'source' => $source,
            'category_id' => $categoryId,
            'auth_mode' => $authMode,
            'status' => 'running',
            'entries' => [],
        ];

        return $id;
    }

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
        $this->runs[$runId]['status'] = $status;
        $this->runs[$runId]['http_status'] = $httpStatus;
        $this->runs[$runId]['items_found'] = $itemsFound;
        $this->runs[$runId]['error_code'] = $errorCode;
    }

    public function addEntries(InstallationId $installation, int $runId, array $entries): void
    {
        foreach ($entries as $entry) {
            $this->runs[$runId]['entries'][] = $entry instanceof HighlightEntry ? $entry->toArray() : $entry;
        }
    }
}
