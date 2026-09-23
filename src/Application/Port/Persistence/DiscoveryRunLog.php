<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Persistence;

use Sinergia\Application\Port\MercadoLivre\HighlightEntry;
use Sinergia\Domain\Installation\InstallationId;

/** Registro técnico de execuções de descoberta/validação, sempre por instalação. */
interface DiscoveryRunLog
{
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
    ): int;

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
    ): void;

    /** @param list<HighlightEntry> $entries */
    public function addEntries(InstallationId $installation, int $runId, array $entries): void;
}
