<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Web\HttpApp;

final class HealthTest extends TestCase
{
    public function testHealthReturnsMinimalJsonWithoutTouchingDatabase(): void
    {
        // Sem DB_*, sem APP_KEY, sem credenciais ML: o /health tem de responder mesmo assim.
        $config = Config::fromArray(['APP_ENV' => 'test', 'APP_VERSION' => '0.1.0', 'DB_PASSWORD' => 'must-not-leak']);
        $app = HttpApp::create(Kernel::container($config, dirname(__DIR__, 3)));

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/health'));
        $body = (string) $response->getBody();
        $json = json_decode($body, true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok', 'service' => 'sinergia-bot-ml', 'version' => '0.1.0'], $json);
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringNotContainsString('must-not-leak', $body);
    }

    public function testUnknownRouteReturnsJson404WithoutTrace(): void
    {
        $config = Config::fromArray(['APP_ENV' => 'production', 'APP_DEBUG' => 'true']);
        $app = HttpApp::create(Kernel::container($config, dirname(__DIR__, 3)));

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/nao-existe'));
        $body = (string) $response->getBody();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['status' => 'error', 'code' => 404, 'message' => 'Recurso não encontrado.'], json_decode($body, true));
        self::assertStringNotContainsString('trace', strtolower($body));
        self::assertStringNotContainsString('.php', $body);
    }

    public function testWrongMethodReturns405(): void
    {
        $config = Config::fromArray(['APP_ENV' => 'test']);
        $app = HttpApp::create(Kernel::container($config, dirname(__DIR__, 3)));

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('POST', '/health'));

        self::assertSame(405, $response->getStatusCode());
    }
}
