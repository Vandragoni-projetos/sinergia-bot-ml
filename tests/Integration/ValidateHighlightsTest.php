<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use Psr\Log\NullLogger;
use Sinergia\Application\Validation\EvidenceWriter;
use Sinergia\Application\Validation\ValidateHighlights;
use Sinergia\Infrastructure\Persistence\DiscoveryRunRepository;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Integration\MercadoLivre\Highlight\HighlightService;
use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Shared\Clock\FrozenClock;
use Sinergia\Tests\Support\FakeMercadoLivre;

/** Fluxo completo da validação (HTTP falso + MariaDB de teste): execução + entradas + evidência. */
final class ValidateHighlightsTest extends DatabaseTestCase
{
    private string $evidenceDir;

    protected function setUp(): void
    {
        // Antes do parent::setUp(): se ele pular o teste (sem TEST_DB_*), o tearDown() ainda roda.
        $this->evidenceDir = sys_get_temp_dir() . '/sinergia-evidence-' . bin2hex(random_bytes(4));
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->evidenceDir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->evidenceDir)) {
            rmdir($this->evidenceDir);
        }
    }

    public function testSuccessfulValidationIsRecordedWithSanitizedEvidence(): void
    {
        $pdo = $this->freshSchema();
        $inst = (new InstallationRepository($pdo))->ensure('default', 'Local', 'MLB')->id;
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, FakeMercadoLivre::fixture('highlights_category.json'), ['Set-Cookie' => 'x=1', 'X-Request-Id' => 'r1']);
        $runs = new DiscoveryRunRepository($pdo);

        $outcome = $this->useCase($fake, $runs)($inst, 'MLB', 'MLB432825', AuthMode::Required, null, null, 'Categoria X');

        self::assertTrue($outcome->succeeded);
        self::assertSame(5, $outcome->summary['items_found']);
        $row = $runs->find($inst, $outcome->runId);
        self::assertSame('succeeded', $row['status']);
        self::assertSame(200, (int) $row['http_status']);
        self::assertSame(5, (int) $row['items_found']);
        self::assertCount(5, $runs->entries($inst, $outcome->runId));

        $evidence = (string) file_get_contents((string) $outcome->evidenceFile);
        self::assertStringNotContainsString(FakeMercadoLivre::TOKEN, $evidence);
        self::assertStringNotContainsString('set-cookie', strtolower($evidence));
        self::assertStringContainsString('MLBU3013800008', $evidence);
        self::assertStringContainsString('Não representa quantidade vendida', $evidence);
    }

    public function testFailedValidationIsRecordedWithOfficialError(): void
    {
        $pdo = $this->freshSchema();
        $inst = (new InstallationRepository($pdo))->ensure('default', 'Local', 'MLB')->id;
        $fake = new FakeMercadoLivre();
        $fake->queueJson(401, ['message' => 'unspecified_token', 'error' => 'unspecified_token', 'status' => 401]);
        $runs = new DiscoveryRunRepository($pdo);

        $outcome = $this->useCase($fake, $runs)($inst, 'MLB', 'MLB432825', AuthMode::Required);

        self::assertFalse($outcome->succeeded);
        self::assertSame(401, $outcome->httpStatus);
        self::assertSame('unspecified_token', $outcome->errorCode);
        $row = $runs->find($inst, $outcome->runId);
        self::assertSame('failed', $row['status']);
        self::assertSame('unspecified_token', $row['error_code']);
        self::assertStringNotContainsString(FakeMercadoLivre::TOKEN, (string) $row['error_message']);
    }

    private function useCase(FakeMercadoLivre $fake, DiscoveryRunRepository $runs): ValidateHighlights
    {
        return new ValidateHighlights(
            new HighlightService($fake->client),
            $runs,
            new EvidenceWriter($this->evidenceDir),
            new FrozenClock('2026-09-22T12:00:00Z'),
            new NullLogger(),
        );
    }
}
