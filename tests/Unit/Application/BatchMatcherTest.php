<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Sinergia\Application\Affiliate\BatchMatcher;
use Sinergia\Application\Affiliate\BatchPreview;
use Sinergia\Application\Affiliate\ExportedItem;
use Sinergia\Application\Affiliate\MeliLaShortLinkFormat;
use Sinergia\Application\Affiliate\ReceivedLine;
use Sinergia\Tests\Support\TestIdFormat;

final class BatchMatcherTest extends TestCase
{
    /** REAL_1 e REAL_2: amostra REAL do Gerador de Links oficial (2026-09-25). REAL_3: fictício, no mesmo formato. */
    private const string REAL_1 = 'https://meli.la/2sWvfQU';
    private const string REAL_2 = 'https://meli.la/1rZgSMj';
    private const string REAL_3 = 'https://meli.la/9QzXk2p';

    public function testCleanBatchIsProposedByPositionAsWeakEvidence(): void
    {
        $preview = $this->matcher()->preview($this->items(3), self::REAL_1 . "\n" . self::REAL_2 . "\n" . self::REAL_3);

        self::assertFalse($preview->hasAnomalies());
        self::assertSame([1, 2, 3], array_map(static fn (ReceivedLine $l) => $l->proposedPosition, $preview->lines));
        foreach ($preview->lines as $line) {
            self::assertSame(ReceivedLine::VALID, $line->formatStatus);
            self::assertSame(ReceivedLine::EVIDENCE_POSITION, $line->evidence, 'Link curto não carrega ID: evidência fraca.');
            self::assertNull($line->detectedProductId);
        }
    }

    public function testRealSampleWithTwoLinksForThreeProductsProposesNothing(): void
    {
        $preview = $this->matcher()->preview($this->items(3), self::REAL_1 . "\n" . self::REAL_2);

        self::assertSame([BatchPreview::COUNT_MISMATCH], $preview->anomalies);
        self::assertCount(2, $preview->lines);
        self::assertSame([null, null], array_map(static fn (ReceivedLine $l) => $l->proposedPosition, $preview->lines));
        self::assertSame([ReceivedLine::VALID, ReceivedLine::VALID], array_map(static fn (ReceivedLine $l) => $l->formatStatus, $preview->lines));
    }

    public function testBlankLinesCrlfAndExactRawText(): void
    {
        $pasted = "\r\n  \n  " . self::REAL_1 . "  \r\n\r\n" . self::REAL_2 . "\n\n";
        $preview = $this->matcher()->preview($this->items(2), $pasted);

        self::assertSame([ReceivedLine::VALID, ReceivedLine::EMPTY, ReceivedLine::VALID], array_map(static fn (ReceivedLine $l) => $l->formatStatus, $preview->lines));
        self::assertContains(BatchPreview::EMPTY_LINE, $preview->anomalies);
        self::assertContains(BatchPreview::COUNT_MISMATCH, $preview->anomalies);
        self::assertSame('  ' . self::REAL_1 . '  ', $preview->lines[0]->raw, 'raw = linha exata (sem o \r terminador).');
        self::assertSame(self::REAL_1, $preview->lines[0]->url);
        self::assertSame([null, null, null], array_map(static fn (ReceivedLine $l) => $l->proposedPosition, $preview->lines));
    }

    public function testDuplicateMarksEveryOccurrence(): void
    {
        $preview = $this->matcher()->preview($this->items(3), self::REAL_1 . "\n" . self::REAL_2 . "\n" . self::REAL_1);

        self::assertSame([ReceivedLine::DUPLICATE, ReceivedLine::VALID, ReceivedLine::DUPLICATE], array_map(static fn (ReceivedLine $l) => $l->formatStatus, $preview->lines));
        self::assertSame([BatchPreview::DUPLICATE], $preview->anomalies);
    }

    public function testOnlyTheObservedOfficialFormatIsAccepted(): void
    {
        $cases = [
            'https://mercadolivre.com/sec/1AbCdEf' => ReceivedLine::INVALID_DOMAIN,     // formato não observado: não é inventado
            'http://meli.la/2sWvfQU' => ReceivedLine::INVALID_DOMAIN,
            'https://meli.la.evil.example/2sWvfQU' => ReceivedLine::INVALID_DOMAIN,
            'https://evil.example/?u=https://meli.la/2sWvfQU' => ReceivedLine::INVALID_DOMAIN,
            'https://meli.la/2sWvfQU?x=1' => ReceivedLine::INVALID_DOMAIN,
            'https://meli.la/2sW vfQU' => ReceivedLine::INVALID_DOMAIN,
            'https://MELI.LA/2sWvfQU' => ReceivedLine::INVALID_DOMAIN,
            'meli.la/2sWvfQU' => ReceivedLine::INVALID,
            'Air Fryer 4L' => ReceivedLine::INVALID,
            self::REAL_1 => ReceivedLine::VALID,
        ];
        foreach ($cases as $text => $expected) {
            self::assertSame($expected, $this->matcher()->preview($this->items(1), $text)->lines[0]->formatStatus, $text);
        }
        self::assertContains(BatchPreview::INVALID_DOMAIN, $this->matcher()->preview($this->items(1), 'https://mercadolivre.com/sec/1AbCdEf')->anomalies);
        self::assertContains(BatchPreview::INVALID_LINE, $this->matcher()->preview($this->items(1), 'texto')->anomalies);
    }

    public function testProductIdentityMatchAndConflictWithAFormatThatCarriesIt(): void
    {
        // Formato FICTÍCIO só de teste: o gerador real observado não traz ID. Exercita a regra de evidência forte.
        $matcher = new BatchMatcher([new MeliLaShortLinkFormat(), new TestIdFormat()]);
        $items = $this->items(2);

        $strong = $matcher->preview($items, "https://ids.example.test/MLB100001/a\nhttps://ids.example.test/MLB100002/b");
        self::assertFalse($strong->hasAnomalies());
        self::assertSame([ReceivedLine::EVIDENCE_PRODUCT_ID, ReceivedLine::EVIDENCE_PRODUCT_ID], array_map(static fn (ReceivedLine $l) => $l->evidence, $strong->lines));

        $conflict = $matcher->preview($items, "https://ids.example.test/MLB100002/a\nhttps://ids.example.test/MLB100001/b");
        self::assertSame([BatchPreview::ID_CONFLICT], $conflict->anomalies);
        self::assertSame(['MLB100002', 'MLB100001'], array_map(static fn (ReceivedLine $l) => $l->detectedProductId, $conflict->lines));
        self::assertSame([null, null], array_map(static fn (ReceivedLine $l) => $l->proposedPosition, $conflict->lines));

        $mixed = $matcher->preview($items, "https://ids.example.test/MLB100001/a\n" . self::REAL_2);
        self::assertSame([ReceivedLine::EVIDENCE_PRODUCT_ID, ReceivedLine::EVIDENCE_POSITION], array_map(static fn (ReceivedLine $l) => $l->evidence, $mixed->lines));
    }

    private function matcher(): BatchMatcher
    {
        return new BatchMatcher([new MeliLaShortLinkFormat()]);
    }

    /** @return list<ExportedItem> */
    private function items(int $n): array
    {
        $items = [];
        for ($i = 1; $i <= $n; $i++) {
            $items[] = new ExportedItem($i, str_repeat((string) $i, 20), $i, 'MLB10000' . $i, 'https://www.mercadolivre.com.br/p/MLB10000' . $i, 'Produto ' . $i);
        }

        return $items;
    }
}

