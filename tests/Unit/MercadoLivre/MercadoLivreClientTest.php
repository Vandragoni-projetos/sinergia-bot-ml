<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\MercadoLivre;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Tests\Support\FakeMercadoLivre;

final class MercadoLivreClientTest extends TestCase
{
    public function testSuccessfulJsonResponse(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['ok' => true], ['X-Request-Id' => 'abc', 'Set-Cookie' => 'sid=secret']);

        $response = $fake->client->get('/sites/MLB/categories');

        self::assertSame(200, $response->status);
        self::assertSame(['ok' => true], $response->json);
        self::assertSame('abc', $response->headers['x-request-id']);
        self::assertArrayNotHasKey('set-cookie', $response->headers, 'Set-Cookie nunca pode ser registrado');
        self::assertSame(AuthMode::Required, $response->authMode);
        self::assertSame('https://api.mercadolibre.com/sites/MLB/categories', (string) $fake->lastRequest()->getUri());
        self::assertSame('Bearer ' . FakeMercadoLivre::TOKEN, $fake->lastRequest()->getHeaderLine('Authorization'));
        self::assertSame('application/json', $fake->lastRequest()->getHeaderLine('Accept'));
    }

    public function testNoAuthorizationHeaderWhenAuthNone(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, []);

        $fake->client->get('/sites/MLB/categories', [], AuthMode::None);

        self::assertFalse($fake->lastRequest()->hasHeader('Authorization'));
    }

    public function testRequiredAuthWithoutProviderFailsBeforeNetwork(): void
    {
        $fake = new FakeMercadoLivre(withToken: false);

        try {
            $fake->client->get('/highlights/MLB/category/MLB1');
            self::fail('Deveria falhar sem credencial.');
        } catch (MercadoLivreException $e) {
            self::assertSame('oauth_not_connected', $e->errorCode);
        }
        self::assertSame([], $fake->history, 'Nenhuma requisição pode ter sido feita.');
    }

    public function testInvalidJson(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueRaw(200, '{not json');

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('não é JSON válido');
        $fake->client->get('/categories/MLB1');
    }

    public function testEmptyBody(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueRaw(200, '   ');

        try {
            $fake->client->get('/categories/MLB1');
            self::fail('Deveria rejeitar corpo vazio.');
        } catch (InvalidResponseException $e) {
            self::assertSame('empty_body', $e->errorCode);
            self::assertSame(200, $e->httpStatus);
        }
    }

    public function testScalarJsonRejected(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueRaw(200, '"texto"');

        try {
            $fake->client->get('/categories/MLB1');
            self::fail('Deveria rejeitar JSON escalar.');
        } catch (InvalidResponseException $e) {
            self::assertSame('unexpected_json_type', $e->errorCode);
        }
    }

    public function testTimeoutBecomesTransportException(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->mock->append(new ConnectException(
            'cURL error 28: Operation timed out after 5001 milliseconds',
            new Request('GET', 'https://api.mercadolibre.com/categories/MLB1'),
        ));

        try {
            $fake->client->get('/categories/MLB1');
            self::fail('Deveria lançar TransportException.');
        } catch (TransportException $e) {
            self::assertSame('timeout', $e->errorCode);
            self::assertNull($e->httpStatus);
            self::assertStringNotContainsString(FakeMercadoLivre::TOKEN, $e->getMessage());
        }
    }

    public function testNetworkErrorBecomesTransportException(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->mock->append(new ConnectException('Could not resolve host', new Request('GET', '/')));

        try {
            $fake->client->get('/categories/MLB1');
            self::fail('Deveria lançar TransportException.');
        } catch (TransportException $e) {
            self::assertSame('network_error', $e->errorCode);
        }
    }

    /** @return iterable<string, array{int, class-string<HttpErrorException>, string}> */
    public static function httpErrors(): iterable
    {
        yield '401' => [401, UnauthorizedException::class, 'unauthorized'];
        yield '403' => [403, ForbiddenException::class, 'forbidden'];
        yield '404' => [404, NotFoundException::class, 'not_found'];
        yield '429' => [429, RateLimitedException::class, 'rate_limited'];
        yield '500' => [500, ServerErrorException::class, 'server_error'];
        yield '503' => [503, ServerErrorException::class, 'server_error'];
        yield '400' => [400, HttpErrorException::class, 'http_error'];
    }

    /** @param class-string<HttpErrorException> $class */
    #[DataProvider('httpErrors')]
    public function testHttpErrorMapping(int $status, string $class, string $code): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson($status, ['message' => 'mensagem oficial', 'error' => 'erro_oficial', 'status' => $status, 'cause' => []]);

        try {
            $fake->client->get('/highlights/MLB/category/MLB1');
            self::fail('Deveria lançar ' . $class);
        } catch (HttpErrorException $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($code, $e->errorCode);
            self::assertSame($status, $e->httpStatus);
            self::assertSame('erro_oficial', $e->mlError);
            self::assertStringContainsString('HTTP ' . $status, $e->getMessage());
            self::assertStringNotContainsString(FakeMercadoLivre::TOKEN, $e->getMessage());
        }
    }

    public function testRateLimitedExposesRetryAfterAndRelatedHeaders(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(429, ['message' => 'too many requests'], ['Retry-After' => '7', 'X-RateLimit-Remaining' => '0']);

        try {
            $fake->client->get('/highlights/MLB/category/MLB1');
            self::fail('Deveria lançar RateLimitedException.');
        } catch (RateLimitedException $e) {
            self::assertSame(7, $e->rateLimit?->retryAfterSeconds);
            self::assertSame('0', $e->rateLimit?->headers['x-ratelimit-remaining'] ?? null);
        }
    }

    public function testErrorBodyEchoingSecretsIsRedacted(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(401, [
            'error' => 'invalid_token',
            'message' => 'token ' . FakeMercadoLivre::TOKEN . ' rejected; refresh TG-5b9032b4e23464aed1f959f-1234567',
        ]);

        try {
            $fake->client->get('/highlights/MLB/category/MLB1');
            self::fail('Deveria lançar UnauthorizedException.');
        } catch (UnauthorizedException $e) {
            self::assertStringNotContainsString(FakeMercadoLivre::TOKEN, $e->getMessage());
            self::assertStringNotContainsString('TG-5b9032b4e23464aed1f959f', $e->getMessage());
            self::assertStringContainsString('[REDACTED]', $e->getMessage());
        }
        self::assertStringNotContainsString(FakeMercadoLivre::TOKEN, $fake->logText());
    }

    public function testLogsNeverContainToken(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['ok' => true]);
        $fake->client->get('/sites/MLB/categories');

        self::assertNotSame('', $fake->logText());
        self::assertStringNotContainsString(FakeMercadoLivre::TOKEN, $fake->logText());
    }

    public function testUnsafePathRejectedBeforeNetwork(): void
    {
        $fake = new FakeMercadoLivre();

        $this->expectException(InvalidArgumentException::class);
        try {
            $fake->client->get('/../oauth/token');
        } finally {
            self::assertSame([], $fake->history);
        }
    }

    public function testQueryStringIsEncoded(): void
    {
        $fake = new FakeMercadoLivre();
        $fake->queueJson(200, ['content' => []]);

        $fake->client->get('/highlights/MLB/category/MLB1', ['attribute' => 'BRAND', 'attributeValue' => '59387']);

        self::assertSame('attribute=BRAND&attributeValue=59387', $fake->lastRequest()->getUri()->getQuery());
    }
}
