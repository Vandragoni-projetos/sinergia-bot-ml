<?php

declare(strict_types=1);

namespace Sinergia\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Evolution API 2.3.7 FALSA (sem rede, sem credencial real), fiel às rotas e aos guards da tag 2.3.7:
 * - instanceExistsGuard: rota com /{instanceName} de instância inexistente → 404;
 * - auth.guard (apikey): aceita a chave GLOBAL ou, com /{instanceName}, o token daquela instância; senão 401;
 *   /instance/fetchInstances aceita o token da instância e devolve só as instâncias com aquele token;
 * - /instance/connect: com a instância "close" passa a "connecting" e devolve {pairingCode, code, base64, count};
 *   com "connecting" devolve o QR atual; com "open" devolve o connectionState;
 * - /group/inviteCode só funciona para grupos em que o número é admin (senão 404 "No invite code");
 * - /instance/logout de instância "close" → 400 (instance is not connected).
 * Permite injetar falhas por prefixo de caminho (status HTTP, 'timeout' ou 'invalid').
 */
final class FakeEvolutionServer
{
    public const string BASE_URL = 'https://evolution.example.test';
    /** Chave global fictícia: existe só para provar que o BotML NUNCA a envia. */
    public const string GLOBAL_KEY = 'TESTE-chave-global-evolution-NAO-USAR';
    public const string RAW_PROVIDER_MESSAGE = 'MENSAGEM-BRUTA-DA-EVOLUTION-NAO-EXIBIR';

    /** @var array<string, array{token: string, state: string, owner: ?string, profile: ?string}> nome → instância */
    public array $instances = [];
    /** @var array<string, list<array<string, mixed>>> nome → grupos no formato do findGroup/fetchAllGroups */
    public array $groups = [];
    /** @var array<string, array<string, string>> nome → [groupJid → inviteCode] (só grupos em que somos admin) */
    public array $inviteCodes = [];
    /** @var array<string, bool> inviteCode → joinApprovalMode */
    public array $joinApproval = [];
    /** @var list<array{method: string, path: string, query: string, apikey: string, body: string}> */
    public array $requests = [];
    /** @var list<array{instance: string, body: array<string, mixed>}> */
    public array $sent = [];
    /** @var array<string, list<int|string>> prefixo do caminho → falhas a aplicar nas próximas chamadas */
    private array $failures = [];
    private int $qrSequence = 0;
    private int $messageSequence = 0;

    /** Instância criada "administrativamente" (fora do BotML); devolve o token (UUID em maiúsculas, como a 2.3.7). */
    public function instance(string $name, string $state = 'close'): string
    {
        $token = strtoupper(sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff)));
        $this->instances[$name] = ['token' => $token, 'state' => $state, 'owner' => null, 'profile' => null];

        return $token;
    }

    /** O celular leu o QR: a instância fica "open" com o número informado. */
    public function pair(string $name, string $phone = '5511987654321', string $profile = 'Loja no WhatsApp'): void
    {
        $this->instances[$name]['state'] = 'open';
        $this->instances[$name]['owner'] = $phone . '@s.whatsapp.net';
        $this->instances[$name]['profile'] = $profile;
    }

    /** O celular desconectou (ou o QR expirou). */
    public function drop(string $name): void
    {
        $this->instances[$name]['state'] = 'close';
    }

    /** Instância removida direto na Evolution (pelo administrador). */
    public function remove(string $name): void
    {
        unset($this->instances[$name]);
    }

    /**
     * Grupo no formato do findGroup da 2.3.7 (com participants).
     *
     * @param list<array{id: string, phoneNumber?: ?string, lid?: ?string, admin: ?string}> $participants
     * @param array<string, mixed>                                                            $overrides
     *
     * @return array<string, mixed>
     */
    public static function group(string $jid, string $subject, array $participants = [], array $overrides = []): array
    {
        return $overrides + [
            'id' => $jid,
            'subject' => $subject,
            'subjectOwner' => '5511900000000@s.whatsapp.net',
            'subjectTime' => 1700000000,
            'pictureUrl' => null,
            'size' => max(1, count($participants)),
            'creation' => 1700000000,
            'owner' => '5511900000000@s.whatsapp.net',
            'desc' => 'Grupo de ofertas',
            'descId' => 'D1',
            'restrict' => true,
            'announce' => true,
            'participants' => $participants,
            'isCommunity' => false,
            'isCommunityAnnounce' => false,
            'linkedParent' => null,
        ];
    }

    public function client(): Client
    {
        return new Client([
            'http_errors' => false,
            'allow_redirects' => false,
            'handler' => fn (RequestInterface $request) => $this->handle($request),
        ]);
    }

    /** Falhas aplicadas, em ordem, às próximas chamadas cujo caminho começa com $prefix. */
    public function fail(string $prefix, int|string ...$failures): void
    {
        $this->failures[$prefix] = array_values($failures);
    }

    /** @return list<string> "MÉTODO /rota" sem o nome da instância */
    public function paths(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . preg_replace('#^(/[a-zA-Z]+/[a-zA-Z]+)/.*$#', '$1', $r['path']), $this->requests);
    }

    public function count(string $methodAndRoute): int
    {
        return count(array_filter($this->paths(), static fn (string $p): bool => $p === $methodAndRoute));
    }

    private function handle(RequestInterface $request): mixed
    {
        $path = $request->getUri()->getPath();
        parse_str($request->getUri()->getQuery(), $query);
        $key = $request->getHeaderLine('apikey');
        $this->requests[] = ['method' => $request->getMethod(), 'path' => $path, 'query' => $request->getUri()->getQuery(), 'apikey' => $key, 'body' => (string) $request->getBody()];

        foreach ($this->failures as $prefix => $list) {
            if ($list !== [] && str_starts_with($path, $prefix)) {
                $failure = array_shift($this->failures[$prefix]);
                if ($failure === 'timeout') {
                    return Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out after 15001 milliseconds', $request));
                }
                if ($failure === 'invalid') {
                    return Create::promiseFor(new Response(200, ['Content-Type' => 'text/html'], '<html>gateway</html>'));
                }

                return $this->json((int) $failure, ['status' => $failure, 'error' => self::RAW_PROVIDER_MESSAGE], $failure === 429 ? ['Retry-After' => '30'] : []);
            }
        }

        if ($path === '/instance/fetchInstances') {
            if ($key === self::GLOBAL_KEY) {
                return $this->json(200, array_values(array_map(fn (string $n): array => $this->info($n), array_keys($this->instances))));
            }
            $mine = array_values(array_filter(array_keys($this->instances), fn (string $n): bool => $this->instances[$n]['token'] === $key
                && (!isset($query['instanceName']) || $query['instanceName'] === $n)));

            return $mine === [] ? $this->json(401, ['status' => 401, 'error' => 'Unauthorized']) : $this->json(200, array_map(fn (string $n): array => $this->info($n), $mine));
        }

        if (preg_match('#^/(instance|group|message)/([a-zA-Z]+)/([^/]+)$#', $path, $m) !== 1) {
            return $this->json(404, ['status' => 404, 'error' => 'Not Found']);
        }
        [, $area, $route, $name] = $m;
        $name = rawurldecode($name);
        if (!isset($this->instances[$name])) {
            return $this->json(404, ['status' => 404, 'error' => 'Not Found', 'response' => ['message' => ['The "' . $name . '" instance does not exist']]]);
        }
        if ($key !== self::GLOBAL_KEY && $key !== $this->instances[$name]['token']) {
            return $this->json(401, ['status' => 401, 'error' => 'Unauthorized', 'response' => ['message' => 'Unauthorized']]);
        }
        $instance = &$this->instances[$name];

        return match ($area . '/' . $route) {
            'instance/connect' => $this->connect($name, isset($query['number'])),
            'instance/connectionState' => $this->json(200, ['instance' => ['instanceName' => $name, 'state' => $instance['state']]]),
            'instance/logout' => $instance['state'] === 'close'
                ? $this->json(400, ['status' => 400, 'error' => 'Bad Request', 'response' => ['message' => ['The "' . $name . '" instance is not connected']]])
                : $this->logout($name),
            'group/fetchAllGroups' => $this->json(200, array_map(static function (array $g): array {
                unset($g['participants']);

                return $g;
            }, $this->groups[$name] ?? [])),
            'group/findGroupInfos' => $this->findGroup($name, (string) ($query['groupJid'] ?? '')),
            'group/inviteCode' => isset($this->inviteCodes[$name][(string) ($query['groupJid'] ?? '')])
                ? $this->json(200, ['inviteUrl' => 'https://chat.whatsapp.com/' . $this->inviteCodes[$name][(string) $query['groupJid']], 'inviteCode' => $this->inviteCodes[$name][(string) $query['groupJid']]])
                : $this->json(404, ['status' => 404, 'error' => 'Not Found', 'response' => ['message' => ['No invite code']]]),
            'group/inviteInfo' => array_key_exists((string) ($query['inviteCode'] ?? ''), $this->joinApproval)
                ? $this->json(200, ['id' => 'x@g.us', 'subject' => 'Grupo', 'joinApprovalMode' => $this->joinApproval[(string) $query['inviteCode']], 'memberAddMode' => false])
                : $this->json(404, ['status' => 404, 'error' => 'Not Found']),
            'message/sendMedia' => $this->sendMedia($name, (string) $request->getBody()),
            default => $this->json(404, ['status' => 404, 'error' => 'Not Found']),
        };
    }

    private function connect(string $name, bool $withNumber): mixed
    {
        $instance = &$this->instances[$name];
        if ($instance['state'] === 'open') {
            return $this->json(200, ['instance' => ['instanceName' => $name, 'state' => 'open']]);
        }
        $instance['state'] = 'connecting';

        return $this->json(200, [
            'pairingCode' => $withNumber ? 'WZYEH1YY' : null,
            'code' => '2@FALSO,' . ++$this->qrSequence,
            'base64' => 'data:image/png;base64,' . base64_encode('QR-FALSO-EVOLUTION-' . $this->qrSequence . '-' . str_repeat('x', 16)),
            'count' => $this->qrSequence,
        ]);
    }

    private function logout(string $name): mixed
    {
        $this->instances[$name]['state'] = 'close';
        $this->instances[$name]['owner'] = null;

        return $this->json(200, ['status' => 'SUCCESS', 'error' => false, 'response' => ['message' => 'Instance logged out']]);
    }

    private function findGroup(string $name, string $jid): mixed
    {
        foreach ($this->groups[$name] ?? [] as $group) {
            if ($group['id'] === $jid) {
                return $this->json(200, $group);
            }
        }

        return $this->json(404, ['status' => 404, 'error' => 'Not Found', 'response' => ['message' => ['Error fetching group']]]);
    }

    private function sendMedia(string $name, string $body): mixed
    {
        $decoded = json_decode($body, true);
        $this->sent[] = ['instance' => $name, 'body' => is_array($decoded) ? $decoded : []];
        $id = sprintf('3EB0FALSO%06d', ++$this->messageSequence);

        return $this->json(201, ['key' => ['remoteJid' => $decoded['number'] ?? null, 'fromMe' => true, 'id' => $id], 'messageType' => 'imageMessage', 'status' => 'PENDING']);
    }

    /** @return array<string, mixed> item do fetchInstances */
    private function info(string $name): array
    {
        $i = $this->instances[$name];

        return ['id' => 'id-' . $name, 'name' => $name, 'connectionStatus' => $i['state'], 'ownerJid' => $i['owner'], 'profileName' => $i['profile'], 'integration' => 'WHATSAPP-BAILEYS'];
    }

    /** @param array<array-key, mixed> $body @param array<string, string> $headers */
    private function json(int $status, array $body, array $headers = []): mixed
    {
        return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
