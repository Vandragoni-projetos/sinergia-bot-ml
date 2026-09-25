<?php

declare(strict_types=1);

namespace Sinergia\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * HTTP falso do Mercado Livre roteado por caminho (sem rede, sem credencial real).
 * Registra cada requisição (caminho + qual token foi enviado) para provar isolamento e chamadas feitas.
 */
final class RoutedMercadoLivreHttp
{
    /** @var array<string, list<array{int, mixed}>> caminho → respostas (a última se repete) */
    private array $routes = [];
    /** @var list<array{path: string, token: string}> */
    public array $requests = [];

    public function client(): Client
    {
        return new Client([
            'http_errors' => false,
            'allow_redirects' => false,
            'handler' => function (RequestInterface $request) {
                $path = $request->getUri()->getPath();
                $this->requests[] = ['path' => $path, 'token' => substr($request->getHeaderLine('Authorization'), 7)];
                $queue = $this->routes[$path] ?? [[404, ['message' => 'resource not found', 'error' => 'not_found', 'status' => 404]]];
                [$status, $body] = count($queue) > 1 ? array_shift($this->routes[$path]) : $queue[0];

                return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
            },
        ]);
    }

    public function on(string $path, int $status, mixed $body): self
    {
        $this->routes[$path] = [[$status, $body]];

        return $this;
    }

    /** Respostas em sequência para o mesmo caminho (a última se repete). */
    public function sequence(string $path, array ...$responses): self
    {
        $this->routes[$path] = array_values($responses);

        return $this;
    }

    /** @param list<array{0: string, 1?: string}> $entries [id, type] */
    public function ranking(string $categoryId, array $entries): self
    {
        $content = [];
        foreach ($entries as $i => $entry) {
            $content[] = ['id' => $entry[0], 'position' => $i + 1, 'type' => $entry[1] ?? 'PRODUCT'];
        }

        return $this->on('/highlights/MLB/category/' . $categoryId, 200, [
            'query_data' => ['highlight_type' => 'BEST_SELLER', 'criteria' => 'CATEGORY', 'id' => $categoryId],
            'content' => $content,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public function product(string $id, string $domain, array $overrides = []): self
    {
        return $this->on('/products/' . $id, 200, $overrides + [
            'id' => $id,
            'name' => 'Produto ' . $id,
            'domain_id' => $domain,
            'status' => 'active',
            'permalink' => 'https://www.mercadolivre.com.br/produto/p/' . $id,
            'pictures' => [['id' => 'P' . $id, 'url' => 'https://http2.mlstatic.com/D_' . $id . '-O.jpg']],
            'buy_box_winner' => null,
        ]);
    }

    /** @param list<array<string, mixed>> $offers */
    public function offers(string $id, array $offers): self
    {
        $results = [];
        foreach ($offers as $i => $offer) {
            $results[] = $offer + [
                'item_id' => 'MLB' . (7000000000 + $i),
                'currency_id' => 'BRL',
                'condition' => 'new',
                'original_price' => null,
                'shipping' => ['free_shipping' => false],
                'official_store_id' => null,
            ];
        }

        return $this->on('/products/' . $id . '/items', 200, ['paging' => ['total' => count($results), 'offset' => 0, 'limit' => 100], 'results' => $results]);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_column($this->requests, 'path');
    }

    public function count(string $path): int
    {
        return count(array_filter($this->paths(), static fn (string $p): bool => $p === $path));
    }

    public function reset(): void
    {
        $this->requests = [];
    }
}
