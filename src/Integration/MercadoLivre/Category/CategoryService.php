<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Category;

use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Application\Port\MercadoLivre\Category;
use Sinergia\Application\Port\MercadoLivre\CategoryReader;
use Sinergia\Application\Port\MercadoLivre\CategoryRef;
use Sinergia\Application\Port\MercadoLivre\SiteRoots;
use Sinergia\Integration\MercadoLivre\Exception\InvalidResponseException;
use Sinergia\Integration\MercadoLivre\Http\ApiResponse;
use Sinergia\Integration\MercadoLivre\Http\MercadoLivreClient;
use Sinergia\Integration\MercadoLivre\MlIds;

/**
 * Árvore oficial de categorias (docs "Domínios e Categorias" / "Categorização de produtos"):
 *   GET /sites/{site_id}/categories  e  GET /categories/{category_id}
 */
final class CategoryService implements CategoryReader
{
    public function __construct(private readonly MercadoLivreClient $client)
    {
    }

    public function siteRoots(string $siteId, AuthMode $auth): SiteRoots
    {
        MlIds::assertSite($siteId);
        $response = $this->client->get('/sites/' . $siteId . '/categories', [], $auth);

        if (!array_is_list($response->json)) {
            throw $this->contract($response, 'lista de categorias esperada');
        }

        $roots = [];
        foreach ($response->json as $row) {
            if (!is_array($row) || !self::nonEmptyString($row['id'] ?? null) || !self::nonEmptyString($row['name'] ?? null)) {
                throw $this->contract($response, 'categoria sem id/name');
            }
            $roots[] = new CategoryRef((string) $row['id'], (string) $row['name']);
        }

        return new SiteRoots($siteId, $roots, $response->meta());
    }

    public function category(string $siteId, string $categoryId, AuthMode $auth): Category
    {
        MlIds::assertCategory($siteId, $categoryId);
        $response = $this->client->get('/categories/' . $categoryId, [], $auth);
        $data = $response->json;

        if (!self::nonEmptyString($data['id'] ?? null) || !self::nonEmptyString($data['name'] ?? null)) {
            throw $this->contract($response, 'campos id/name ausentes');
        }

        $path = [];
        foreach (self::listOrEmpty($data['path_from_root'] ?? null) as $node) {
            if (!is_array($node) || !self::nonEmptyString($node['id'] ?? null) || !self::nonEmptyString($node['name'] ?? null)) {
                throw $this->contract($response, 'nó inválido em path_from_root');
            }
            $path[] = new CategoryRef((string) $node['id'], (string) $node['name']);
        }

        $children = [];
        foreach (self::listOrEmpty($data['children_categories'] ?? null) as $child) {
            if (!is_array($child) || !self::nonEmptyString($child['id'] ?? null) || !self::nonEmptyString($child['name'] ?? null)) {
                throw $this->contract($response, 'filho inválido em children_categories');
            }
            $children[] = new CategoryRef(
                (string) $child['id'],
                (string) $child['name'],
                isset($child['total_items_in_this_category']) && is_int($child['total_items_in_this_category'])
                    ? $child['total_items_in_this_category'] : null,
            );
        }

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $domain = $settings['catalog_domain'] ?? null;

        return new Category(
            id: (string) $data['id'],
            name: (string) $data['name'],
            pathFromRoot: $path,
            children: $children,
            catalogDomain: self::nonEmptyString($domain) ? (string) $domain : null,
            totalItems: isset($data['total_items_in_this_category']) && is_int($data['total_items_in_this_category'])
                ? $data['total_items_in_this_category'] : null,
            permalink: self::nonEmptyString($data['permalink'] ?? null) ? (string) $data['permalink'] : null,
            fetchedAt: $response->fetchedAt,
            meta: $response->meta(),
        );
    }

    private function contract(ApiResponse $response, string $what): InvalidResponseException
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

    private static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** @return list<mixed> */
    private static function listOrEmpty(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
