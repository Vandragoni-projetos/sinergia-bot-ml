<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Product;

use Sinergia\Application\Offer\CatalogProduct;
use Sinergia\Application\Offer\ProductOffer;
use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Integration\MercadoLivre\Exception\InvalidArgumentException;
use Sinergia\Integration\MercadoLivre\Exception\InvalidResponseException;
use Sinergia\Integration\MercadoLivre\Http\ApiResponse;
use Sinergia\Integration\MercadoLivre\Http\MercadoLivreClient;

/**
 * Produtos de catálogo (validados na Etapa 0 com o token de produção):
 *   GET /products/{id}        → nome, domain_id, fotos, permalink, status, buy_box_winner
 *   GET /products/{id}/items  → ofertas (item_id, price, original_price, currency_id, condition, frete, loja oficial)
 * Nunca chama /items/{id} nem /user-products/{id} (403 para a nossa credencial).
 */
final class ProductService
{
    public function __construct(private readonly MercadoLivreClient $client)
    {
    }

    public function product(string $productId): CatalogProduct
    {
        self::assertCatalogId($productId);

        return self::parseProduct($productId, $this->client->get('/products/' . $productId, [], AuthMode::Required));
    }

    /** @return array{offers: list<ProductOffer>, total: ?int} */
    public function offers(string $productId): array
    {
        self::assertCatalogId($productId);

        return self::parseOffers($this->client->get('/products/' . $productId . '/items', [], AuthMode::Required));
    }

    public static function parseProduct(string $productId, ApiResponse $response): CatalogProduct
    {
        $data = $response->json;
        if (($data['id'] ?? null) !== $productId || !self::text($data['name'] ?? null)) {
            throw self::contract($response, 'id/name ausentes ou divergentes');
        }

        $picture = null;
        $pictures = is_array($data['pictures'] ?? null) && array_is_list($data['pictures']) ? $data['pictures'] : [];
        $count = 0;
        foreach ($pictures as $row) {
            $url = is_array($row) ? ($row['secure_url'] ?? $row['url'] ?? null) : null;
            if (is_string($url) && self::isHttpsUrl($url)) {
                $count++;
                $picture ??= mb_substr($url, 0, 512);
            }
        }

        $permalink = $data['permalink'] ?? null;
        $permalink = is_string($permalink) && self::isMercadoLivreUrl($permalink) ? mb_substr($permalink, 0, 512) : null;

        $buyBox = is_array($data['buy_box_winner'] ?? null) ? self::offer($data['buy_box_winner']) : null;

        return new CatalogProduct(
            $productId,
            mb_substr(trim((string) $data['name']), 0, 255),
            self::text($data['domain_id'] ?? null) ? mb_substr((string) $data['domain_id'], 0, 96) : null,
            $permalink,
            $picture,
            $count,
            self::text($data['status'] ?? null) ? (string) $data['status'] : null,
            $buyBox,
        );
    }

    /** @return array{offers: list<ProductOffer>, total: ?int} */
    public static function parseOffers(ApiResponse $response): array
    {
        $results = $response->json['results'] ?? null;
        if (!is_array($results) || !array_is_list($results)) {
            throw self::contract($response, 'campo "results" (lista) ausente');
        }
        $offers = [];
        foreach ($results as $row) {
            $offer = is_array($row) ? self::offer($row) : null;
            if ($offer !== null) {
                $offers[] = $offer;
            }
        }
        $total = $response->json['paging']['total'] ?? null;

        return ['offers' => $offers, 'total' => is_int($total) ? $total : null];
    }

    /** @param array<array-key, mixed> $row */
    private static function offer(array $row): ?ProductOffer
    {
        $itemId = $row['item_id'] ?? null;
        if (!is_string($itemId) || preg_match('/^[A-Z]{3}\d{1,20}$/', $itemId) !== 1) {
            return null;
        }
        $shipping = is_array($row['shipping'] ?? null) ? $row['shipping'] : [];

        return new ProductOffer(
            $itemId,
            self::cents($row['price'] ?? null) ?? 0,
            self::cents($row['original_price'] ?? null),
            is_string($row['currency_id'] ?? null) ? $row['currency_id'] : '',
            is_string($row['condition'] ?? null) ? $row['condition'] : null,
            ($shipping['free_shipping'] ?? false) === true,
            is_int($row['official_store_id'] ?? null) && $row['official_store_id'] > 0,
        );
    }

    private static function cents(mixed $value): ?int
    {
        if ((!is_int($value) && !is_float($value)) || $value <= 0 || $value > 100_000_000) {
            return null;
        }

        return (int) round($value * 100);
    }

    private static function assertCatalogId(string $productId): void
    {
        // Só produtos de catálogo MLB…; MLBU (user product) e IDs arbitrários nunca viram chamada.
        if (preg_match('/^MLB\d{1,20}$/', $productId) !== 1) {
            throw new InvalidArgumentException('ID de produto de catálogo inválido (esperado MLB + dígitos).', 'invalid_product_id');
        }
    }

    private static function isHttpsUrl(string $url): bool
    {
        return str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function isMercadoLivreUrl(string $url): bool
    {
        $host = self::isHttpsUrl($url) ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && ($host === 'mercadolivre.com.br' || str_ends_with($host, '.mercadolivre.com.br'));
    }

    private static function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function contract(ApiResponse $response, string $what): InvalidResponseException
    {
        return new InvalidResponseException(
            sprintf('Resposta de %s fora do contrato esperado: %s.', $response->path, $what),
            'contract_violation',
            httpStatus: $response->status,
            path: $response->path,
            headers: $response->headers,
            rateLimit: $response->rateLimit,
            durationMs: $response->durationMs,
        );
    }
}
