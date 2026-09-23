<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Highlight;

use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Application\Port\MercadoLivre\HighlightEntry;
use Sinergia\Application\Port\MercadoLivre\HighlightReader;
use Sinergia\Application\Port\MercadoLivre\HighlightResult;
use Sinergia\Integration\MercadoLivre\Exception\InvalidResponseException;
use Sinergia\Integration\MercadoLivre\Http\ApiResponse;
use Sinergia\Integration\MercadoLivre\Http\MercadoLivreClient;
use Sinergia\Integration\MercadoLivre\MlIds;

/**
 * Ranking oficial de mais vendidos por categoria (docs "Mais vendidos no Mercado Livre"):
 *   GET /highlights/{site_id}/category/{category_id}[?attribute=&attributeValue=]
 * Documentado: até 20 elementos; só categorias-folha; exige Bearer (401 unspecified_token).
 */
final class HighlightService implements HighlightReader
{
    public const int DOCUMENTED_MAX_ENTRIES = 20;

    public function __construct(private readonly MercadoLivreClient $client)
    {
    }

    public function topByCategory(
        string $siteId,
        string $categoryId,
        AuthMode $auth,
        ?string $attribute = null,
        ?string $attributeValue = null,
    ): HighlightResult {
        MlIds::assertCategory($siteId, $categoryId);
        MlIds::assertAttributeFilter($attribute, $attributeValue);

        $query = $attribute === null ? [] : ['attribute' => $attribute, 'attributeValue' => (string) $attributeValue];
        $response = $this->client->get('/highlights/' . $siteId . '/category/' . $categoryId, $query, $auth);

        return self::parse($siteId, $categoryId, $response);
    }

    public static function parse(string $siteId, string $categoryId, ApiResponse $response): HighlightResult
    {
        $data = $response->json;
        $warnings = [];

        if (!array_key_exists('content', $data) || !is_array($data['content']) || !array_is_list($data['content'])) {
            throw self::contract($response, 'campo "content" (lista) ausente');
        }

        $queryData = is_array($data['query_data'] ?? null) ? $data['query_data'] : null;
        if ($queryData === null) {
            $warnings[] = 'query_data ausente (documentado como presente).';
        }
        $highlightType = self::optString($queryData['highlight_type'] ?? null);
        $criteria = self::optString($queryData['criteria'] ?? null);
        $queryId = self::optString($queryData['id'] ?? null);
        if ($queryId !== null && $queryId !== $categoryId) {
            $warnings[] = sprintf('query_data.id (%s) difere da categoria consultada (%s).', $queryId, $categoryId);
        }
        if ($highlightType !== null && $highlightType !== 'BEST_SELLER') {
            $warnings[] = sprintf('highlight_type "%s" diferente do documentado (BEST_SELLER).', $highlightType);
        }

        $entries = [];
        $seenPositions = [];
        foreach ($data['content'] as $index => $row) {
            if (!is_array($row)) {
                throw self::contract($response, sprintf('elemento %d de content não é objeto', $index));
            }
            $id = $row['id'] ?? null;
            $position = $row['position'] ?? null;
            if (!is_string($id) || trim($id) === '') {
                throw self::contract($response, sprintf('elemento %d sem "id"', $index));
            }
            if (!is_int($position) || $position < 1) {
                throw self::contract($response, sprintf('elemento %d com "position" inválida', $index));
            }
            $type = self::optString($row['type'] ?? null);
            $entry = new HighlightEntry($id, $position, $type);
            if ($type === null) {
                $warnings[] = sprintf('posição %d sem "type".', $position);
            } elseif (!$entry->isDocumentedType()) {
                $warnings[] = sprintf('posição %d com type não documentado: %s.', $position, $type);
            }
            if (isset($seenPositions[$position])) {
                $warnings[] = sprintf('posição %d repetida.', $position);
            }
            $seenPositions[$position] = true;
            $entries[] = $entry;
        }

        if (count($entries) > self::DOCUMENTED_MAX_ENTRIES) {
            $warnings[] = sprintf('%d elementos retornados (documentado: até %d).', count($entries), self::DOCUMENTED_MAX_ENTRIES);
        }

        usort($entries, static fn (HighlightEntry $a, HighlightEntry $b): int => $a->position <=> $b->position);

        return new HighlightResult($siteId, $categoryId, $highlightType, $criteria, $queryId, $entries, $warnings, $response->meta());
    }

    private static function contract(ApiResponse $response, string $what): InvalidResponseException
    {
        return new InvalidResponseException(
            sprintf('Resposta de %s fora do contrato documentado: %s.', $response->path, $what),
            'contract_violation',
            httpStatus: $response->status,
            path: $response->path,
            headers: $response->headers,
            rateLimit: $response->rateLimit,
            durationMs: $response->durationMs,
        );
    }

    private static function optString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
