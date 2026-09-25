<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/**
 * Pré-visualização da importação (plano F1 v2.1, seção 3). Puro: não grava nada e não abre nenhum link.
 *
 * 1. Linhas: separa por "\n" (um "\r" final é só terminador de linha). Linhas em branco no INÍCIO e no FIM são
 *    ignoradas; em branco no MEIO viram "empty". raw guarda a linha exata; url = raw sem espaços nas pontas.
 * 2. Formato de cada linha: valid | invalid_domain (é URL, mas não de formato aceito) | invalid (não é URL) |
 *    duplicate (mesmo link em mais de uma linha: TODAS as ocorrências, para não escolher uma em silêncio).
 * 3. Evidência: o formato traz ID do produto e ele é o da posição → product_id (forte); traz outro ID → conflict;
 *    não traz ID → position_only (fraca).
 * 4. Proposta automática SÓ quando: quantidade de linhas = quantidade exportada, todas válidas, sem duplicidade e
 *    sem conflito. Qualquer anomalia → nada proposto; o cliente associa manualmente linha a linha.
 */
final class BatchMatcher
{
    /** @param list<AffiliateUrlFormat> $formats */
    public function __construct(private readonly array $formats)
    {
    }

    /** @param list<ExportedItem> $items ordenados por posição */
    public function preview(array $items, string $pasted): BatchPreview
    {
        $rawLines = explode("\n", $pasted);
        $rawLines = array_map(static fn (string $l): string => str_ends_with($l, "\r") ? substr($l, 0, -1) : $l, $rawLines);
        while ($rawLines !== [] && trim($rawLines[0]) === '') {
            array_shift($rawLines);
        }
        while ($rawLines !== [] && trim($rawLines[array_key_last($rawLines)]) === '') {
            array_pop($rawLines);
        }

        $byPosition = [];
        foreach ($items as $item) {
            $byPosition[$item->position] = $item;
        }
        $counts = array_count_values(array_filter(array_map('trim', $rawLines), static fn (string $u): bool => $u !== ''));

        $parsed = [];
        $anomalies = [];
        foreach (array_values($rawLines) as $index => $raw) {
            $lineNo = $index + 1;
            $url = trim($raw);
            $format = $this->format($url);
            $status = match (true) {
                $url === '' => ReceivedLine::EMPTY,
                $format === null => MeliLaShortLinkFormat::looksLikeUrl($url) ? ReceivedLine::INVALID_DOMAIN : ReceivedLine::INVALID,
                ($counts[$url] ?? 0) > 1 => ReceivedLine::DUPLICATE,
                default => ReceivedLine::VALID,
            };
            $detected = $status === ReceivedLine::VALID && $format !== null ? $format->productId($url) : null;
            $expected = $byPosition[$lineNo] ?? null;
            $evidence = match (true) {
                $status !== ReceivedLine::VALID => ReceivedLine::EVIDENCE_NONE,
                $detected === null => ReceivedLine::EVIDENCE_POSITION,
                $expected !== null && $detected === $expected->productId => ReceivedLine::EVIDENCE_PRODUCT_ID,
                default => ReceivedLine::EVIDENCE_CONFLICT,
            };

            $anomalies[] = match ($status) {
                ReceivedLine::EMPTY => BatchPreview::EMPTY_LINE,
                ReceivedLine::DUPLICATE => BatchPreview::DUPLICATE,
                ReceivedLine::INVALID_DOMAIN => BatchPreview::INVALID_DOMAIN,
                ReceivedLine::INVALID => BatchPreview::INVALID_LINE,
                default => $evidence === ReceivedLine::EVIDENCE_CONFLICT ? BatchPreview::ID_CONFLICT : null,
            };
            $parsed[] = [$lineNo, $raw, $url, $status, $detected, $evidence];
        }
        if (count($rawLines) !== count($items)) {
            $anomalies[] = BatchPreview::COUNT_MISMATCH;
        }
        $anomalies = array_values(array_unique(array_filter($anomalies)));

        $lines = [];
        foreach ($parsed as [$lineNo, $raw, $url, $status, $detected, $evidence]) {
            $lines[] = new ReceivedLine(
                $lineNo, $raw, $url, $status, $detected, $evidence,
                $anomalies === [] && isset($byPosition[$lineNo]) ? $lineNo : null,
            );
        }

        return new BatchPreview($lines, $anomalies);
    }

    public function format(string $url): ?AffiliateUrlFormat
    {
        foreach ($this->formats as $format) {
            if ($format->accepts($url)) {
                return $format;
            }
        }

        return null;
    }
}
