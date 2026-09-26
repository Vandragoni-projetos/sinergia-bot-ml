<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\WhatsApp;

use GuzzleHttp\Psr7\HttpFactory;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sinergia\Application\Port\WhatsApp\ConnectionSnapshot;
use Sinergia\Application\Port\WhatsApp\WhatsAppProviderFailure;
use Sinergia\Integration\WhatsApp\Evolution\EvolutionCredential;
use Sinergia\Integration\WhatsApp\Evolution\EvolutionProvider;
use Sinergia\Shared\Config\EvolutionConfig;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Logging\LoggerFactory;
use Sinergia\Tests\Support\FakeEvolutionServer;

/** EvolutionProvider contra a Evolution 2.3.7 FALSA: só o token da própria instância, nunca a chave global. */
final class EvolutionProviderTest extends TestCase
{
    private const string NAME = 'sbm-1-botml';
    private const string GROUP = '120363000000000101@g.us';
    private const string ME = '5511987654321@s.whatsapp.net';

    private FakeEvolutionServer $server;
    private TestHandler $logs;
    private EvolutionProvider $provider;
    private string $token;
    private SensitiveValue $credential;

    protected function setUp(): void
    {
        $this->server = new FakeEvolutionServer();
        $this->logs = new TestHandler();
        $factory = new HttpFactory();
        $this->provider = new EvolutionProvider($this->server->client(), $factory, $factory, new EvolutionConfig(FakeEvolutionServer::BASE_URL, 5), LoggerFactory::create('local', $this->logs));
        $this->token = $this->server->instance(self::NAME);
        $this->credential = EvolutionCredential::compose(self::NAME, new SensitiveValue($this->token));
    }

    public function testQrLifecycleUsesOnlyTheInstanceTokenInTheApikeyHeader(): void
    {
        $connecting = $this->provider->connect($this->credential);
        self::assertSame(ConnectionSnapshot::CONNECTING, $connecting->state);
        self::assertStringStartsWith('data:image/png;base64,', (string) $connecting->qrCode);
        self::assertNull($connecting->pairCode);

        // connecting: connectionState não traz QR; o provedor busca o QR ATUAL em /instance/connect (sem efeito colateral).
        $waiting = $this->provider->status($this->credential);
        self::assertSame(ConnectionSnapshot::CONNECTING, $waiting->state);
        self::assertNotSame($connecting->qrCode, $waiting->qrCode, 'Cada consulta devolve o QR atual.');

        $this->server->pair(self::NAME);
        $open = $this->provider->status($this->credential);
        self::assertTrue($open->isConnected());
        self::assertSame('5511987654321', $open->phone);
        self::assertSame('Loja no WhatsApp', $open->profileName);
        self::assertNull($open->qrCode);

        // Já conectada: /instance/connect devolve o estado (não gera QR).
        self::assertTrue($this->provider->connect($this->credential)->isConnected());

        self::assertSame(ConnectionSnapshot::DISCONNECTED, $this->provider->disconnect($this->credential)->state);
        self::assertSame(ConnectionSnapshot::DISCONNECTED, $this->provider->status($this->credential)->state);
        // Logout é idempotente (a 2.3.7 responde 400 "not connected").
        self::assertSame(ConnectionSnapshot::DISCONNECTED, $this->provider->disconnect($this->credential)->state);

        // Reconexão: a instância e o token continuam valendo depois do logout.
        $again = $this->provider->connect($this->credential);
        self::assertSame(ConnectionSnapshot::CONNECTING, $again->state);
        self::assertNotNull($again->qrCode);

        self::assertSame([
            'GET /instance/connect', 'GET /instance/connectionState', 'GET /instance/connect', 'GET /instance/connectionState',
            'GET /instance/fetchInstances', 'GET /instance/connect', 'GET /instance/fetchInstances', 'DELETE /instance/logout', 'GET /instance/connectionState',
            'DELETE /instance/logout', 'GET /instance/connect',
        ], $this->server->paths());
        $this->assertOnlyInstanceTokenUsed();
    }

    public function testPairingCodeRequestSendsTheNumberAsQueryAndNothingSecret(): void
    {
        $snapshot = $this->provider->connect($this->credential, '5511999998888');
        self::assertSame('WZYEH1YY', $snapshot->pairCode);
        self::assertSame('number=5511999998888', $this->server->requests[0]['query']);

        $this->expectException(\InvalidArgumentException::class);
        $this->provider->connect($this->credential, "5511999998888\n");
    }

    public function testGroupsAreListedAndAdminGroupInfoBringsInviteAndJoinApproval(): void
    {
        $this->server->pair(self::NAME);
        $this->server->groups[self::NAME] = [
            FakeEvolutionServer::group(self::GROUP, '  Ofertas da Loja  ', [['id' => self::ME, 'admin' => 'superadmin'], ['id' => '5511911112222@s.whatsapp.net', 'admin' => null]]),
            FakeEvolutionServer::group('120363000000000202@g.us', 'Avisos', [], ['isCommunityAnnounce' => true]),
            FakeEvolutionServer::group('120363000000000303@g.us', 'Subgrupo', [], ['linkedParent' => '120363000000000999@g.us']),
            FakeEvolutionServer::group('120363000000000404@g.us', 'Sem campos de comunidade', [], ['isCommunity' => null]),
            ['id' => 'sem-jid-valido', 'subject' => 'ignorado'],
        ];
        $this->server->inviteCodes[self::NAME][self::GROUP] = 'AbCdEf123456';
        $this->server->joinApproval['AbCdEf123456'] = false;

        $page = $this->provider->listGroups($this->credential, 500, 0);
        self::assertCount(4, $page->groups, 'Entrada sem JID de grupo é descartada.');
        self::assertSame(4, $page->total);
        [$main, $announce, $sub, $unknown] = $page->groups;
        self::assertSame([self::GROUP, 'Ofertas da Loja', true, 2, false], [$main->jid, $main->name, $main->announceOnly, $main->participants, $main->isCommunity]);
        self::assertNull($main->ownerIsAdmin, 'A listagem não informa admin: desconhecido, nunca "não".');
        self::assertNull($main->joinApprovalRequired);
        self::assertNull($main->hasInviteLink);
        self::assertTrue($announce->isCommunity);
        self::assertTrue($sub->isCommunity, 'Subgrupo de comunidade (linkedParent).');
        self::assertNull($unknown->isCommunity);
        self::assertSame([], $this->provider->listGroups($this->credential, 500, 500)->groups, 'Sem paginação na 2.3.7: página seguinte vazia.');
        self::assertStringContainsString('getParticipants=false', $this->server->requests[0]['query']);

        $info = $this->provider->groupInfo($this->credential, self::GROUP);
        self::assertSame([true, true, false, false], [$info->ownerIsAdmin, $info->hasInviteLink, $info->joinApprovalRequired, $info->isCommunity]);
        self::assertSame(['GET /group/findGroupInfos', 'GET /group/inviteCode', 'GET /group/inviteInfo'], array_slice($this->server->paths(), 1));
        self::assertSame('inviteCode=AbCdEf123456', end($this->server->requests)['query']);
        $this->assertOnlyInstanceTokenUsed();
    }

    public function testNonAdminGroupUsesParticipantsAndUnknownStaysNull(): void
    {
        $this->server->pair(self::NAME);
        $this->server->groups[self::NAME] = [
            FakeEvolutionServer::group(self::GROUP, 'Grupo de terceiros', [['id' => self::ME, 'admin' => null]]),
            FakeEvolutionServer::group('120363000000000202@g.us', 'Grupo com LID', [['id' => '123456789012345@lid', 'admin' => 'admin']]),
            FakeEvolutionServer::group('120363000000000303@g.us', 'Grupo com LID e número', [['id' => '123456789012345@lid', 'phoneNumber' => self::ME, 'admin' => 'admin']]),
        ];

        $notAdmin = $this->provider->groupInfo($this->credential, self::GROUP);
        self::assertSame([false, null, null], [$notAdmin->ownerIsAdmin, $notAdmin->hasInviteLink, $notAdmin->joinApprovalRequired]);

        $lid = $this->provider->groupInfo($this->credential, '120363000000000202@g.us');
        self::assertNull($lid->ownerIsAdmin, 'Participante só com LID não é identificável: desconhecido.');

        $lidWithPhone = $this->provider->groupInfo($this->credential, '120363000000000303@g.us');
        self::assertTrue($lidWithPhone->ownerIsAdmin);
        self::assertNull($lidWithPhone->hasInviteLink, 'Sem código de convite o link fica desconhecido.');

        $this->expectException(\InvalidArgumentException::class);
        $this->provider->groupInfo($this->credential, '120363000000000101@g.us/../../x');
    }

    public function testGroupNotFoundAndInvalidInviteResponsesAreHandled(): void
    {
        $this->server->pair(self::NAME);
        try {
            $this->provider->groupInfo($this->credential, self::GROUP);
            self::fail('Grupo inexistente deveria falhar.');
        } catch (WhatsAppProviderFailure $e) {
            self::assertSame(WhatsAppProviderFailure::NOT_FOUND, $e->errorCode);
        }

        // Erro de infraestrutura no inviteCode NÃO é confundido com "não é admin".
        $this->server->groups[self::NAME] = [FakeEvolutionServer::group(self::GROUP, 'Grupo', [])];
        $this->server->fail('/group/inviteCode', 503);
        try {
            $this->provider->groupInfo($this->credential, self::GROUP);
            self::fail('5xx no inviteCode deveria propagar.');
        } catch (WhatsAppProviderFailure $e) {
            self::assertSame(WhatsAppProviderFailure::SERVER_ERROR, $e->errorCode);
        }
    }

    public function testSendImageWithCaption(): void
    {
        $this->server->pair(self::NAME);
        $sent = $this->provider->sendImage($this->credential, self::GROUP, 'https://http2.mlstatic.com/D_foto.jpg', "Air Fryer\n\nPor R$ 199,90\n\nhttps://meli.la/AbC123");
        self::assertSame('3EB0FALSO000001', $sent->providerMessageId);
        self::assertSame([[
            'instance' => self::NAME,
            'body' => ['number' => self::GROUP, 'mediatype' => 'image', 'media' => 'https://http2.mlstatic.com/D_foto.jpg', 'caption' => "Air Fryer\n\nPor R$ 199,90\n\nhttps://meli.la/AbC123"],
        ]], $this->server->sent);
        self::assertSame(['POST /message/sendMedia'], $this->server->paths());

        foreach ([['120363@newsletter', 'https://x.test/a.jpg'], [self::GROUP, 'http://x.test/a.jpg'], [self::GROUP . "\n", 'https://x.test/a.jpg']] as [$chat, $image]) {
            try {
                $this->provider->sendImage($this->credential, $chat, $image, 'x');
                self::fail('Destino/imagem inválidos deveriam ser recusados.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount(1, $this->server->requests, 'Entrada inválida não chega à Evolution.');
    }

    /** @return iterable<string, array{int|string, string, ?int}> */
    public static function failures(): iterable
    {
        yield '401' => [401, WhatsAppProviderFailure::UNAUTHORIZED, null];
        yield '403' => [403, WhatsAppProviderFailure::FORBIDDEN, null];
        yield '404' => [404, WhatsAppProviderFailure::NOT_FOUND, null];
        yield '429' => [429, WhatsAppProviderFailure::RATE_LIMITED, 30];
        yield '500' => [500, WhatsAppProviderFailure::SERVER_ERROR, null];
        yield '503' => [503, WhatsAppProviderFailure::SERVER_ERROR, null];
        yield 'timeout' => ['timeout', WhatsAppProviderFailure::TIMEOUT, null];
        yield 'json inválido' => ['invalid', WhatsAppProviderFailure::INVALID_RESPONSE, null];
    }

    #[DataProvider('failures')]
    public function testFailuresAreTypedAndNeverLeakTokenOrProviderText(int|string $failure, string $code, ?int $retryAfter): void
    {
        $this->server->pair(self::NAME);
        $this->server->fail('/message/sendMedia', $failure);
        try {
            $this->provider->sendImage($this->credential, self::GROUP, 'https://x.test/a.jpg', 'legenda');
            self::fail('Deveria falhar.');
        } catch (WhatsAppProviderFailure $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($retryAfter, $e->retryAfterSeconds);
            self::assertSame('send_media', $e->operation);
            $text = $e->getMessage() . $e->getTraceAsString() . $this->logText();
            self::assertStringNotContainsString($this->token, $text);
            self::assertStringNotContainsString(FakeEvolutionServer::RAW_PROVIDER_MESSAGE, $text);
        }
    }

    public function testWrongTokenOrRemovedInstanceAreTypedFailures(): void
    {
        $stale = EvolutionCredential::compose(self::NAME, new SensitiveValue('TOKEN-ANTIGO-00000000'));
        try {
            $this->provider->status($stale);
            self::fail('Token errado deveria dar 401.');
        } catch (WhatsAppProviderFailure $e) {
            self::assertSame(WhatsAppProviderFailure::UNAUTHORIZED, $e->errorCode);
        }

        $this->server->remove(self::NAME);
        try {
            $this->provider->connect($this->credential);
            self::fail('Instância removida deveria dar 404.');
        } catch (WhatsAppProviderFailure $e) {
            self::assertSame(WhatsAppProviderFailure::NOT_FOUND, $e->errorCode);
        }
        self::assertStringNotContainsString($this->token, $this->logText());
    }

    public function testInvalidStoredCredentialNeverCallsTheServerNorEchoesIt(): void
    {
        $uazapiToken = new SensitiveValue('TESTE-token-da-uazapi-abcdef');
        foreach (['connect', 'status', 'disconnect', 'listGroups', 'groupInfo', 'sendImage'] as $method) {
            try {
                match ($method) {
                    'connect' => $this->provider->connect($uazapiToken),
                    'status' => $this->provider->status($uazapiToken),
                    'disconnect' => $this->provider->disconnect($uazapiToken),
                    'listGroups' => $this->provider->listGroups($uazapiToken, 500, 0),
                    'groupInfo' => $this->provider->groupInfo($uazapiToken, self::GROUP),
                    'sendImage' => $this->provider->sendImage($uazapiToken, self::GROUP, 'https://x.test/a.jpg', 'x'),
                };
                self::fail($method . ' deveria recusar a credencial.');
            } catch (WhatsAppProviderFailure $e) {
                self::assertSame(WhatsAppProviderFailure::UNAUTHORIZED, $e->errorCode, $method);
                self::assertStringNotContainsString('TESTE-token-da-uazapi', $e->getMessage() . $e->getTraceAsString(), $method);
            }
        }
        self::assertSame([], $this->server->requests);
        self::assertStringNotContainsString('TESTE-token-da-uazapi', $this->logText());
    }

    public function testNeverCreatesInstancesAndChannelsAreOutOfScope(): void
    {
        try {
            $this->provider->createInstance('sbm-9-x1y2z3w4');
            self::fail('Opção C: o BotML nunca cria instância.');
        } catch (WhatsAppProviderFailure $e) {
            self::assertSame(WhatsAppProviderFailure::FORBIDDEN, $e->errorCode);
        }
        self::assertSame([], $this->provider->listChannels($this->credential));
        self::assertSame([], $this->server->requests, 'Nenhuma chamada: nem criação, nem canais.');
    }

    public function testUnexpectedStateIsRejected(): void
    {
        $this->server->instances[self::NAME]['state'] = 'refused';
        $this->expectException(WhatsAppProviderFailure::class);
        $this->provider->status($this->credential);
    }

    private function assertOnlyInstanceTokenUsed(): void
    {
        foreach ($this->server->requests as $request) {
            self::assertSame($this->token, $request['apikey'], 'Só o token da própria instância, no cabeçalho apikey.');
            self::assertNotSame(FakeEvolutionServer::GLOBAL_KEY, $request['apikey']);
            self::assertStringNotContainsString($this->token, $request['path'] . '?' . $request['query'], 'Token nunca na URL.');
        }
        self::assertStringNotContainsString($this->token, $this->logText());
    }

    private function logText(): string
    {
        return implode("\n", array_map(static fn ($r): string => (string) json_encode($r->toArray()), $this->logs->getRecords()));
    }
}
