<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Integration\MercadoLivre\Exception\ForbiddenException;
use Sinergia\Integration\MercadoLivre\Exception\HttpErrorException;
use Sinergia\Integration\MercadoLivre\Exception\InvalidArgumentException;
use Sinergia\Integration\MercadoLivre\Exception\InvalidResponseException;
use Sinergia\Integration\MercadoLivre\Exception\MercadoLivreException;
use Sinergia\Integration\MercadoLivre\Exception\NotFoundException;
use Sinergia\Integration\MercadoLivre\Exception\RateLimitedException;
use Sinergia\Integration\MercadoLivre\Exception\ServerErrorException;
use Sinergia\Integration\MercadoLivre\Exception\TransportException;
use Sinergia\Integration\MercadoLivre\Exception\UnauthorizedException;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Config\MercadoLivreConfig;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Security\Redactor;

/**
 * Cliente HTTP do Mercado Livre (implementação própria, pequena e testável).
 *
 * Responsabilidades: base URL, cabeçalhos, autenticação por Bearer quando exigida,
 * timeout (configurado no cliente PSR-18), tratamento de status HTTP, parsing JSON,
 * erros tipados e metadados de rate limit. NÃO contém regra de negócio.
 */
final class MercadoLivreClient
{
    /** Cabeçalhos de resposta considerados não sensíveis e úteis para diagnóstico. */
    private const array HEADER_ALLOWLIST = [
        'content-type', 'content-length', 'date', 'cache-control', 'age', 'etag', 'last-modified',
        'expires', 'vary', 'location', 'x-request-id', 'x-trace-id', 'x-cache', 'via', 'server',
        'retry-after', 'x-content-type-options', 'strict-transport-security',
    ];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly MercadoLivreConfig $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly ?AccessTokenProvider $tokens = null,
    ) {
    }

    public function withTokenProvider(AccessTokenProvider $tokens): self
    {
        return new self($this->http, $this->requests, $this->streams, $this->config, $this->clock, $this->logger, $tokens);
    }

    public function hasTokenProvider(): bool
    {
        return $this->tokens !== null;
    }

    /** @param array<string, scalar> $query */
    public function get(string $path, array $query = [], AuthMode $auth = AuthMode::Required): ApiResponse
    {
        $this->assertSafePath($path);
        $url = rtrim($this->config->apiBaseUrl, '/') . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $request = $this->requests->createRequest('GET', $url);
        if ($auth === AuthMode::Required) {
            if ($this->tokens === null) {
                throw new MercadoLivreException(
                    'Chamada exige OAuth, mas nenhuma credencial do Mercado Livre está conectada.',
                    'oauth_not_connected',
                    path: $path,
                );
            }
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->tokens->accessToken()->reveal());
        }

        return $this->send($request, 'GET', $path, $auth);
    }

    /**
     * POST application/x-www-form-urlencoded, sem Authorization (usado no /oauth/token).
     *
     * @param array<string, string|SensitiveValue> $form
     */
    public function postForm(string $path, array $form): ApiResponse
    {
        $this->assertSafePath($path);
        $plain = [];
        foreach ($form as $key => $value) {
            $plain[$key] = $value instanceof SensitiveValue ? $value->reveal() : $value;
        }
        $body = http_build_query($plain, '', '&', PHP_QUERY_RFC3986);
        unset($plain);

        $request = $this->requests->createRequest('POST', rtrim($this->config->apiBaseUrl, '/') . $path)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streams->createStream($body));

        return $this->send($request, 'POST', $path, AuthMode::None);
    }

    private function send(RequestInterface $request, string $method, string $path, AuthMode $auth): ApiResponse
    {
        $request = $request
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->config->userAgent);

        $started = hrtime(true);
        try {
            $response = $this->http->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            $timeout = stripos($e->getMessage(), 'timed out') !== false || str_contains($e->getMessage(), 'cURL error 28');
            $this->logFailure($method, $path, $auth, null, $timeout ? 'timeout' : 'network_error', $this->elapsedMs($started));
            throw new TransportException(
                $timeout ? sprintf('Tempo esgotado ao chamar %s %s.', $method, $path) : sprintf('Falha de rede ao chamar %s %s.', $method, $path),
                $timeout ? 'timeout' : 'network_error',
                path: $path,
                durationMs: $this->elapsedMs($started),
                previous: null,
            );
        } catch (ClientExceptionInterface $e) {
            $this->logFailure($method, $path, $auth, null, 'http_client_error', $this->elapsedMs($started));
            throw new TransportException(
                sprintf('Erro do cliente HTTP ao chamar %s %s.', $method, $path),
                'http_client_error',
                path: $path,
                durationMs: $this->elapsedMs($started),
            );
        }

        $durationMs = $this->elapsedMs($started);
        $status = $response->getStatusCode();
        $allHeaders = $this->lowercaseHeaders($response);
        $headers = $this->allowlistedHeaders($allHeaders);
        $rateLimit = RateLimitInfo::fromHeaders($allHeaders);
        $raw = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            $this->logFailure($method, $path, $auth, $status, 'http_' . $status, $durationMs);
            throw $this->httpError($status, $path, $raw, $headers, $rateLimit, $durationMs);
        }

        if (trim($raw) === '') {
            throw new InvalidResponseException(
                sprintf('Resposta vazia (HTTP %d) em %s.', $status, $path),
                'empty_body',
                httpStatus: $status,
                path: $path,
                headers: $headers,
                rateLimit: $rateLimit,
                durationMs: $durationMs,
            );
        }

        try {
            $json = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidResponseException(
                sprintf('Resposta não é JSON válido (HTTP %d) em %s.', $status, $path),
                'invalid_json',
                httpStatus: $status,
                path: $path,
                headers: $headers,
                rateLimit: $rateLimit,
                durationMs: $durationMs,
            );
        }
        if (!is_array($json)) {
            throw new InvalidResponseException(
                sprintf('JSON com tipo inesperado (HTTP %d) em %s.', $status, $path),
                'unexpected_json_type',
                httpStatus: $status,
                path: $path,
                headers: $headers,
                rateLimit: $rateLimit,
                durationMs: $durationMs,
            );
        }

        $this->logger->info('ml.request', [
            'method' => $method,
            'endpoint' => $path,
            'http_status' => $status,
            'duration_ms' => $durationMs,
            'auth' => $auth->value,
        ]);

        return new ApiResponse($method, $path, $status, $headers, $json, $raw, $durationMs, $rateLimit, $auth, $this->clock->now());
    }

    /**
     * @param array<string, string> $headers
     */
    private function httpError(
        int $status,
        string $path,
        string $raw,
        array $headers,
        RateLimitInfo $rateLimit,
        int $durationMs,
    ): HttpErrorException {
        $mlError = null;
        $mlMessage = null;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $mlError = isset($decoded['error']) && is_scalar($decoded['error']) ? (string) $decoded['error'] : null;
            $mlMessage = isset($decoded['message']) && is_scalar($decoded['message']) ? (string) $decoded['message'] : null;
        }

        $detail = '';
        if ($mlError !== null || $mlMessage !== null) {
            $detail = sprintf(' (error=%s; message=%s)', mb_substr($mlError ?? '-', 0, 80), mb_substr($mlMessage ?? '-', 0, 200));
        }
        $message = Redactor::redactText(sprintf('Mercado Livre respondeu HTTP %d em %s%s.', $status, $path, $detail));

        [$class, $code] = match (true) {
            $status === 401 => [UnauthorizedException::class, 'unauthorized'],
            $status === 403 => [ForbiddenException::class, 'forbidden'],
            $status === 404 => [NotFoundException::class, 'not_found'],
            $status === 429 => [RateLimitedException::class, 'rate_limited'],
            $status >= 500 => [ServerErrorException::class, 'server_error'],
            $status >= 300 && $status < 400 => [HttpErrorException::class, 'unexpected_redirect'],
            default => [HttpErrorException::class, 'http_error'],
        };

        return new $class($message, $code, $status, $path, $mlError, $headers, $rateLimit, $durationMs);
    }

    private function assertSafePath(string $path): void
    {
        if (preg_match('#^/[A-Za-z0-9/_\-.]*$#', $path) !== 1 || str_contains($path, '..')) {
            throw new InvalidArgumentException('Caminho de API inválido.', 'invalid_path');
        }
    }

    /** @return array<string, string> */
    private function lowercaseHeaders(ResponseInterface $response): array
    {
        $out = [];
        foreach ($response->getHeaders() as $name => $values) {
            $out[strtolower((string) $name)] = implode(', ', $values);
        }

        return $out;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function allowlistedHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (in_array($name, self::HEADER_ALLOWLIST, true) || str_contains($name, 'ratelimit') || str_contains($name, 'rate-limit')) {
                $out[$name] = Redactor::redactText($value);
            }
        }
        ksort($out);

        return $out;
    }

    private function elapsedMs(int|float $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }

    private function logFailure(string $method, string $path, AuthMode $auth, ?int $status, string $code, int $durationMs): void
    {
        $this->logger->warning('ml.request_failed', [
            'method' => $method,
            'endpoint' => $path,
            'http_status' => $status,
            'error_code' => $code,
            'duration_ms' => $durationMs,
            'auth' => $auth->value,
        ]);
    }
}
