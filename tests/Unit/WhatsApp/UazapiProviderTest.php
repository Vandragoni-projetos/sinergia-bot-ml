<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\WhatsApp;

use GuzzleHttp\Psr7\HttpFactory;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use Sinergia\Application\Port\WhatsApp\ConnectionSnapshot;
use Sinergia\Application\Port\WhatsApp\WhatsAppProviderFailure;
use Sinergia\Integration\WhatsApp\Uazapi\UazapiProvider;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Config\UazapiConfig;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Tests\Support\FakeUazapiServer;

final class UazapiProviderTest extends TestCase
{
    private FakeUazapiServer $server;
    private TestHandler $logs;
    private UazapiProvider $provider;

    protected function setUp(): void
    {
        $this->server = new FakeUazapiServer();
        $this->logs = new TestHandler();
        $factory = new HttpFactory();
        $this->provider = new UazapiProvider(
            $this->server->client(),
            $factory,
            $factory,
            new UazapiConfig(FakeUazapiServer::BASE_URL, new SensitiveValue(FakeUazapiServer::ADMIN_TOKEN), 5),
            LoggerFactory::create('local', $this->logs),
        );
    }

    public function testLifecycleUsesHeadersOnly(): void
    {
        $instance = $this->provider->createInstance('sbm-7-abcd1234');
        $token = $instance->token;

        $connecting = $this->provider->connect($token);
        self::assertSame(ConnectionSnapshot::CONNECTING, $connecting->state);
        self::assertStringStartsWith('data:image/png;base64,', (string) $connecting->qrCode);
        self::assertNull($connecting->pairCode);

        $this->server->pair($token->reveal());
        $connected = $this->provider->status($token);
        self::assertTrue($connected->isConnected());
        self::assertSame('5511987654321', $connected->phone);
        self::assertSame('Loja no WhatsApp', $connected->profileName);
        self::assertNull($connected->qrCode, 'QR só existe enquanto conecta.');

        self::assertSame(ConnectionSnapshot::DISCONNECTED, $this->provider->disconnect($token)->state);
        self::assertSame(ConnectionSnapshot::DISCONNECTED, $this->provider->status($token)->state);

        self::assertSame(
            ['POST /instance/create', 'POST /instance/connect', 'GET /instance/status', 'POST /instance/disconnect', 'GET /instance/status'],
            $this->server->paths(),
        );
        foreach ($this->server->requests as $i => $request) {
            self::assertSame('', (string) parse_url($request['uri'], PHP_URL_QUERY), 'Nada na query string.');
            self::assertSame($i === 0 ? FakeUazapiServer::ADMIN_TOKEN : '', $request['admintoken']);
            self::assertSame($i === 0 ? '' : $token->reveal(), $request['token']);
        }
        $logs = json_encode(array_map(static fn ($r) => $r->toArray(), $this->logs->getRecords()));
        self::assertStringNotContainsString(FakeUazapiServer::ADMIN_TOKEN, (string) $logs);
        self::assertStringNotContainsString($token->reveal(), (string) $logs);
    }

    public function testPairingCode(): void
    {
        $token = $this->provider->createInstance('sbm-7-abcd1234')->token;
        $snapshot = $this->provider->connect($token, '5511987654321');

        self::assertSame('ABCD-1234', $snapshot->pairCode);
        self::assertNull($snapshot->qrCode);
        self::assertSame('{"phone":"5511987654321"}', $this->server->requests[1]['body']);

        $this->expectException(\InvalidArgumentException::class);
        $this->provider->connect($token, '12');
    }

    /** @return iterable<string, array{int|string, string, ?int}> */
    public static function failures(): iterable
    {
        yield '401' => [401, WhatsAppProviderFailure::UNAUTHORIZED, null];
        yield '403' => [403, WhatsAppProviderFailure::FORBIDDEN, null];
        yield '404' => [404, WhatsAppProviderFailure::NOT_FOUND, null];
        yield '409' => [409, WhatsAppProviderFailure::CONFLICT, null];
        yield '429' => [429, WhatsAppProviderFailure::RATE_LIMITED, 30];
        yield '500' => [500, WhatsAppProviderFailure::SERVER_ERROR, null];
        yield '503' => [503, WhatsAppProviderFailure::SERVER_ERROR, null];
        yield '400' => [400, WhatsAppProviderFailure::HTTP_ERROR, null];
        yield 'timeout' => ['timeout', WhatsAppProviderFailure::TIMEOUT, null];
        yield 'não JSON' => ['invalid', WhatsAppProviderFailure::INVALID_RESPONSE, null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailuresAreTypedAndSanitized(int|string $failure, string $code, ?int $retryAfter): void
    {
        $token = $this->provider->createInstance('sbm-7-abcd1234')->token;
        $this->server->fail('/instance/status', $failure);
        try {
            $this->provider->status($token);
            self::fail('Deveria falhar.');
        } catch (WhatsAppProviderFailure $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($retryAfter, $e->retryAfterSeconds);
            self::assertStringNotContainsString(FakeUazapiServer::RAW_PROVIDER_MESSAGE, $e->getMessage());
            self::assertStringNotContainsString($token->reveal(), $e->getMessage());
        }
        self::assertStringNotContainsString(FakeUazapiServer::RAW_PROVIDER_MESSAGE, (string) json_encode(array_map(static fn ($r) => $r->toArray(), $this->logs->getRecords())));
    }

    public function testIncompleteResponsesAreRejected(): void
    {
        $server = new class {
            public static function provider(array $body): UazapiProvider
            {
                $factory = new HttpFactory();
                $client = new \GuzzleHttp\Client(['handler' => static fn () => \GuzzleHttp\Promise\Create::promiseFor(
                    new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body)),
                )]);

                return new UazapiProvider($client, $factory, $factory, new UazapiConfig('https://u.example.test', new SensitiveValue('x'), 5), LoggerFactory::create('local', new TestHandler()));
            }
        };
        $token = new SensitiveValue('t');

        foreach ([[], ['instance' => ['status' => 'estranho']], ['instance' => []]] as $body) {
            try {
                $server::provider($body)->status($token);
                self::fail('Deveria rejeitar ' . json_encode($body));
            } catch (WhatsAppProviderFailure $e) {
                self::assertSame(WhatsAppProviderFailure::INVALID_RESPONSE, $e->errorCode);
            }
        }
        try {
            $server::provider(['response' => 'ok'])->createInstance('sbm-1-aaaa');
            self::fail('Criação sem token deveria falhar.');
        } catch (WhatsAppProviderFailure $e) {
            self::assertSame(WhatsAppProviderFailure::INVALID_RESPONSE, $e->errorCode);
        }

        // Campos fora do formato são descartados (nunca repassados ao HTML).
        $snapshot = $server::provider(['instance' => ['status' => 'connecting', 'qrcode' => 'javascript:alert(1)', 'paircode' => '<b>x</b>']])->status($token);
        self::assertNull($snapshot->qrCode);
        self::assertNull($snapshot->pairCode);
        // Sem instance.status, o booleano documentado status.connected é aceito.
        self::assertTrue($server::provider(['status' => ['connected' => true, 'jid' => ['user' => '5511900000000']]])->status($token)->isConnected());
    }
}
