<?php

declare(strict_types=1);

namespace Sinergia\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Servidor Uazapi FALSO (sem rede, sem credencial real), com estado por token de instância.
 * - exige o admintoken fictício em /instance/create e um token existente nas rotas de instância;
 * - gera um QR diferente a cada consulta (prova que o painel nunca mostra QR antigo);
 * - permite injetar falhas (HTTP, timeout, JSON inválido) e ganchos para simular concorrência.
 */
final class FakeUazapiServer
{
    public const string BASE_URL = 'https://uazapi.example.test';
    public const string ADMIN_TOKEN = 'TESTE-admintoken-ficticio-0001';
    public const string RAW_PROVIDER_MESSAGE = 'MENSAGEM-BRUTA-DO-PROVEDOR-NAO-EXIBIR';

    /** @var array<string, array{name: string, state: string, phone: ?string, profile: ?string, mode: ?string}> */
    public array $instances = [];
    /** @var list<array{method: string, path: string, uri: string, token: string, admintoken: string, body: string}> */
    public array $requests = [];
    /** @var array<string, list<int|string>> caminho → falhas a aplicar nas próximas chamadas (status HTTP, 'timeout' ou 'invalid') */
    private array $failures = [];
    /** @var array<string, \Closure(): void> */
    public array $before = [];
    private int $counter = 0;
    private int $qrSequence = 0;
    /** @var array<string, list<array<string, mixed>>> token → grupos no formato da Uazapi (chave "_invite" = tem link) */
    public array $groups = [];
    /** @var array<string, list<array<string, mixed>>> token → canais seguidos */
    public array $channels = [];
    /** @var list<array{token: string, body: string}> */
    public array $sent = [];

    /** Cria uma instância já conectada e devolve o token (atalho para os testes de destinos). */
    public function connectedInstance(string $name = 'sbm-teste'): string
    {
        $token = sprintf('TESTE-instancia-%02d-%s', ++$this->counter, bin2hex(random_bytes(6)));
        $this->instances[$token] = ['name' => $name, 'state' => 'connected', 'phone' => '5511987654321', 'profile' => 'Loja', 'mode' => null];

        return $token;
    }

    /**
     * Grupo no formato documentado (Group). Por padrão: somos admin, sem aprovação, só admins enviam, não é comunidade.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function group(string $jid, string $name, array $overrides = []): array
    {
        return $overrides + [
            'JID' => $jid,
            'Name' => $name,
            'OwnerIsAdmin' => true,
            'IsJoinApprovalRequired' => false,
            'IsAnnounce' => true,
            'IsParent' => false,
            'IsDefaultSubGroup' => false,
            'ParticipantCount' => 120,
            '_invite' => true,
        ];
    }

    /** @return array<string, mixed> canal no formato do whatsmeow (viewer_metadata.role) */
    public static function channel(string $jid, string $name, ?string $role = 'owner'): array
    {
        $row = ['id' => $jid, 'state' => ['type' => 'active'], 'thread_metadata' => ['name' => ['text' => $name], 'subscribers_count' => '350']];
        if ($role !== null) {
            $row['viewer_metadata'] = ['mute' => 'off', 'role' => $role];
        }

        return $row;
    }

    public function client(): Client
    {
        return new Client([
            'http_errors' => false,
            'allow_redirects' => false,
            'handler' => fn (RequestInterface $request) => $this->handle($request),
        ]);
    }

    /** Falhas aplicadas, em ordem, às próximas chamadas deste caminho. */
    public function fail(string $path, int|string ...$failures): void
    {
        $this->failures[$path] = array_values($failures);
    }

    /** Simula o celular lendo o QR: a instância passa a "connected". */
    public function pair(string $token, string $phone = '5511987654321'): void
    {
        $this->instances[$token]['state'] = 'connected';
        $this->instances[$token]['phone'] = $phone;
        $this->instances[$token]['profile'] = 'Loja no WhatsApp';
    }

    /** Simula o QR expirando no provedor. */
    public function expire(string $token): void
    {
        $this->instances[$token]['state'] = 'disconnected';
    }

    /** Remove a instância no provedor (ex.: servidor apagou): o token passa a dar 401. */
    public function delete(string $token): void
    {
        unset($this->instances[$token]);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], $this->requests);
    }

    public function count(string $methodAndPath): int
    {
        return count(array_filter($this->paths(), static fn (string $p): bool => $p === $methodAndPath));
    }

    /** @return list<string> tokens de instância usados nas chamadas (cabeçalho "token") */
    public function tokensUsed(): array
    {
        return array_values(array_unique(array_filter(array_column($this->requests, 'token'))));
    }

    private function handle(RequestInterface $request): mixed
    {
        $path = $request->getUri()->getPath();
        $token = $request->getHeaderLine('token');
        $this->requests[] = [
            'method' => $request->getMethod(),
            'path' => $path,
            'uri' => (string) $request->getUri(),
            'token' => $token,
            'admintoken' => $request->getHeaderLine('admintoken'),
            'body' => (string) $request->getBody(),
        ];
        if (isset($this->before[$path])) {
            ($this->before[$path])();
        }

        $failure = isset($this->failures[$path]) && $this->failures[$path] !== [] ? array_shift($this->failures[$path]) : null;
        if ($failure === 'timeout') {
            return Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out after 15001 milliseconds', $request));
        }
        if ($failure === 'invalid') {
            return Create::promiseFor(new Response(200, ['Content-Type' => 'text/html'], '<html>gateway</html>'));
        }
        if (is_int($failure)) {
            return $this->json($failure, ['error' => self::RAW_PROVIDER_MESSAGE], $failure === 429 ? ['Retry-After' => '30'] : []);
        }

        if ($path === '/instance/create') {
            if ($request->getHeaderLine('admintoken') !== self::ADMIN_TOKEN) {
                return $this->json(401, ['error' => 'invalid admintoken']);
            }
            $body = json_decode((string) $request->getBody(), true);
            $newToken = sprintf('TESTE-instancia-%02d-%s', ++$this->counter, bin2hex(random_bytes(6)));
            $this->instances[$newToken] = ['name' => (string) ($body['name'] ?? ''), 'state' => 'disconnected', 'phone' => null, 'profile' => null, 'mode' => null];

            return $this->json(200, [
                'response' => 'Instance created successfully',
                'instance' => ['id' => 'r' . $this->counter, 'token' => $newToken, 'status' => 'disconnected', 'name' => $body['name'] ?? ''],
                'status' => ['connected' => false, 'loggedIn' => false, 'jid' => null],
                'name' => $body['name'] ?? '',
                'token' => $newToken,
            ]);
        }

        if (!isset($this->instances[$token])) {
            return $this->json(401, ['error' => 'instance info not found']);
        }
        $instance = &$this->instances[$token];

        if ($path === '/instance/connect') {
            if ($instance['state'] === 'connected') {
                return $this->json(200, $this->statusBody($instance));
            }
            $body = json_decode((string) $request->getBody(), true);
            $instance['state'] = 'connecting';
            $instance['mode'] = isset($body['phone']) ? 'paircode' : 'qr';

            return $this->json(200, $this->statusBody($instance) + ['connected' => false, 'loggedIn' => false, 'jid' => null]);
        }
        if ($path === '/instance/status' && $request->getMethod() === 'GET') {
            return $this->json(200, $this->statusBody($instance));
        }
        if ($path === '/group/list') {
            $body = json_decode((string) $request->getBody(), true);
            $all = array_map(static fn (array $g): array => array_diff_key($g, ['_invite' => 1]), $this->groups[$token] ?? []);
            $page = array_slice($all, (int) ($body['offset'] ?? 0), (int) ($body['limit'] ?? 50));

            return $this->json(200, ['groups' => $page, 'pagination' => ['totalRecords' => count($all), 'limit' => (int) ($body['limit'] ?? 50), 'offset' => (int) ($body['offset'] ?? 0)]]);
        }
        if ($path === '/group/info') {
            $body = json_decode((string) $request->getBody(), true);
            foreach ($this->groups[$token] ?? [] as $group) {
                if ($group['JID'] === ($body['groupjid'] ?? null)) {
                    $invite = ($group['_invite'] ?? false) === true && ($body['getInviteLink'] ?? false) === true;
                    $group = array_diff_key($group, ['_invite' => 1]);
                    if ($invite) {
                        $group['invite_link'] = 'https://chat.whatsapp.com/ConviteFicticio' . substr(md5($group['JID']), 0, 8);
                    }

                    return $this->json(200, $group);
                }
            }

            return $this->json(404, ['error' => 'group not found or not a participant']);
        }
        if ($path === '/newsletter/list') {
            return $this->json(200, ['response' => $this->channels[$token] ?? []]);
        }
        if ($path === '/send/media') {
            $this->sent[] = ['token' => $token, 'body' => (string) $request->getBody()];

            return $this->json(200, ['id' => 'r1', 'messageid' => '3EB0FICTICIO' . count($this->sent)]);
        }
        if ($path === '/instance/disconnect') {
            $instance['state'] = 'disconnected';
            $instance['phone'] = null;

            return $this->json(200, $this->statusBody($instance) + ['response' => 'Disconnected']);
        }

        return $this->json(404, ['error' => 'not found']);
    }

    /**
     * @param array{name: string, state: string, phone: ?string, profile: ?string, mode: ?string} $instance
     *
     * @return array<string, mixed>
     */
    private function statusBody(array $instance): array
    {
        $body = ['instance' => ['id' => 'r1', 'name' => $instance['name'], 'status' => $instance['state']]];
        if ($instance['state'] === 'connecting') {
            if ($instance['mode'] === 'paircode') {
                $body['instance']['paircode'] = 'ABCD-1234';
            } else {
                $body['instance']['qrcode'] = 'data:image/png;base64,' . base64_encode('QR-FALSO-NUMERO-' . ++$this->qrSequence . '-' . str_repeat('x', 16));
            }
        }
        if ($instance['state'] === 'connected') {
            $body['instance']['profileName'] = $instance['profile'];
        }
        $body['status'] = [
            'connected' => $instance['state'] === 'connected',
            'loggedIn' => $instance['state'] === 'connected',
            'jid' => $instance['phone'] === null ? null : ['user' => $instance['phone'], 'agent' => 0, 'device' => 0, 'server' => 's.whatsapp.net'],
        ];

        return $body;
    }

    /** @param array<string, string> $headers */
    private function json(int $status, array $body, array $headers = []): mixed
    {
        return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
