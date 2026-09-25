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

    public function testGroupAndChannelSignalsKeepUnknownAsNull(): void
    {
        $token = $this->server->connectedInstance();
        $this->server->groups[$token] = [
            FakeUazapiServer::group('120363000000000001@g.us', 'Aberto'),
            ['JID' => '120363000000000002@g.us', 'Name' => 'Sem campos', '_invite' => true],
            FakeUazapiServer::group('120363000000000003@g.us', 'Subgrupo', ['IsDefaultSubGroup' => true, 'OwnerIsAdmin' => false, '_invite' => false]),
            ['JID' => 'lixo', 'Name' => 'ignorado'],
        ];
        $this->server->channels[$token] = [
            FakeUazapiServer::channel('120363111111111111@newsletter', 'Dono', 'OWNER'),
            FakeUazapiServer::channel('120363111111111112@newsletter', 'Seguidor', 'subscriber'),
            FakeUazapiServer::channel('120363111111111113@newsletter', 'Sem papel', null),
            ['id' => 'nao-e-canal@g.us'],
        ];
        $secret = new SensitiveValue($token);

        $page = $this->provider->listGroups($secret, 50, 0);
        self::assertCount(3, $page->groups);
        [$open, $bare, $sub] = $page->groups;
        self::assertSame([true, false, true, false, null], [$open->ownerIsAdmin, $open->joinApprovalRequired, $open->announceOnly, $open->isCommunity, $open->hasInviteLink], 'A listagem não traz o link.');
        self::assertSame([null, null, null, null], [$bare->ownerIsAdmin, $bare->joinApprovalRequired, $bare->announceOnly, $bare->isCommunity]);
        self::assertSame([false, true], [$sub->ownerIsAdmin, $sub->isCommunity]);
        self::assertStringContainsString('"noParticipants":true', $this->server->requests[0]['body']);

        $info = $this->provider->groupInfo($secret, '120363000000000001@g.us');
        self::assertTrue($info->hasInviteLink);
        self::assertStringContainsString('"getInviteLink":true', $this->server->requests[1]['body']);
        self::assertNull($this->provider->groupInfo($secret, '120363000000000003@g.us')->hasInviteLink, 'Sem link na resposta = desconhecido.');

        $channels = $this->provider->listChannels($secret);
        self::assertSame(['Dono', 'Seguidor', 'Sem papel'], array_map(static fn ($c) => $c->name, $channels));
        self::assertSame([true, false, null], array_map(static fn ($c) => $c->weAreAdmin, $channels));

        $this->expectException(\InvalidArgumentException::class);
        $this->provider->groupInfo($secret, 'nao-e-jid');
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
