<?php

declare(strict_types=1);

namespace Sinergia\Integration\WhatsApp\Uazapi;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Sinergia\Application\Port\WhatsApp\ChannelSummary;
use Sinergia\Application\Port\WhatsApp\ConnectionSnapshot;
use Sinergia\Application\Port\WhatsApp\GroupPage;
use Sinergia\Application\Port\WhatsApp\GroupSummary;
use Sinergia\Application\Port\WhatsApp\ProviderInstance;
use Sinergia\Application\Port\WhatsApp\SentMessage;
use Sinergia\Application\Port\WhatsApp\WhatsAppProvider;
use Sinergia\Application\Port\WhatsApp\WhatsAppProviderFailure as Failure;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Shared\Config\UazapiConfig;

/**
 * uazapiGO v2 (docs.uazapi.com, conferido em 2026-09-25):
 *   POST /instance/create      (admintoken) → token da instância
 *   POST /instance/connect     (token)      → QR (sem phone) ou paircode (com phone)
 *   GET  /instance/status      (token)
 *   POST /instance/disconnect  (token)
 *   POST /group/list           (token)      → grupos (etapa 6)
 *   POST /group/info           (token)      → detalhes + link de convite (só admin)
 *   GET  /newsletter/list      (token)      → canais seguidos
 *   POST /send/media           (token)      → etapa 6
 * Credenciais só em CABEÇALHOS (nunca na URL). Respostas de erro do provedor não são repassadas.
 */
final class UazapiProvider implements WhatsAppProvider
{
    private const array STATES = ['disconnected', 'connecting', 'connected', 'hibernated'];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly UazapiConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function createInstance(string $name): ProviderInstance
    {
        if (preg_match('/^[a-z0-9-]{3,64}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Nome de instância inválido.');
        }
        $json = $this->send('create', 'POST', '/instance/create', ['admintoken' => $this->config->adminToken], ['name' => $name]);
        $instance = is_array($json['instance'] ?? null) ? $json['instance'] : [];
        $token = $json['token'] ?? $instance['token'] ?? null;
        if (!is_string($token) || trim($token) === '' || strlen($token) > 512) {
            throw new Failure(Failure::INVALID_RESPONSE, 200, null, 'create');
        }
        $id = $instance['id'] ?? null;

        return new ProviderInstance(is_string($id) && $id !== '' ? mb_substr($id, 0, 64) : null, $name, new SensitiveValue($token));
    }

    public function connect(SensitiveValue $instanceToken, ?string $phone = null): ConnectionSnapshot
    {
        if ($phone !== null && preg_match('/^\d{10,15}$/', $phone) !== 1) {
            throw new \InvalidArgumentException('Telefone inválido para código de pareamento.');
        }
        $json = $this->send('connect', 'POST', '/instance/connect', ['token' => $instanceToken], $phone === null ? new \stdClass() : ['phone' => $phone]);

        return $this->snapshot('connect', $json);
    }

    public function status(SensitiveValue $instanceToken): ConnectionSnapshot
    {
        return $this->snapshot('status', $this->send('status', 'GET', '/instance/status', ['token' => $instanceToken]));
    }

    public function disconnect(SensitiveValue $instanceToken): ConnectionSnapshot
    {
        $json = $this->send('disconnect', 'POST', '/instance/disconnect', ['token' => $instanceToken], new \stdClass());

        // Resposta documentada traz a instância; se faltar o estado, a sessão foi encerrada de qualquer forma.
        return isset($json['instance']['status']) || isset($json['status']['connected'])
            ? $this->snapshot('disconnect', $json)
            : new ConnectionSnapshot(ConnectionSnapshot::DISCONNECTED);
    }

    public function listGroups(SensitiveValue $instanceToken, int $limit, int $offset): GroupPage
    {
        $json = $this->send('group_list', 'POST', '/group/list', ['token' => $instanceToken], [
            'limit' => max(1, min($limit, 1000)), 'offset' => max(0, $offset), 'noParticipants' => true,
        ]);
        if (!is_array($json['groups'] ?? null) || !array_is_list($json['groups'])) {
            throw new Failure(Failure::INVALID_RESPONSE, 200, null, 'group_list');
        }
        $groups = [];
        foreach ($json['groups'] as $row) {
            $group = is_array($row) ? self::group($row) : null;
            if ($group !== null) {
                $groups[] = $group;
            }
        }
        $total = $json['pagination']['totalRecords'] ?? null;

        return new GroupPage($groups, is_int($total) ? $total : null);
    }

    public function groupInfo(SensitiveValue $instanceToken, string $groupJid): GroupSummary
    {
        if (preg_match('/^[0-9-]{5,64}@g\.us$/', $groupJid) !== 1) {
            throw new \InvalidArgumentException('JID de grupo inválido.');
        }
        $json = $this->send('group_info', 'POST', '/group/info', ['token' => $instanceToken], ['groupjid' => $groupJid, 'getInviteLink' => true]);
        // A resposta é o próprio Group (ou, em algumas versões, embrulhada em "group").
        $row = is_array($json['group'] ?? null) ? $json['group'] : $json;
        $group = self::group($row);
        if ($group === null || $group->jid !== $groupJid) {
            throw new Failure(Failure::INVALID_RESPONSE, 200, null, 'group_info');
        }

        return $group;
    }

    public function listChannels(SensitiveValue $instanceToken): array
    {
        $json = $this->send('newsletter_list', 'GET', '/newsletter/list', ['token' => $instanceToken]);
        $rows = $json['response'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new Failure(Failure::INVALID_RESPONSE, 200, null, 'newsletter_list');
        }
        $channels = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            // O schema dos itens não é documentado: aceita só o que tiver JID @newsletter explícito.
            $jid = $row['id'] ?? $row['jid'] ?? $row['JID'] ?? null;
            if (!is_string($jid) || preg_match('/^[0-9]{5,64}@newsletter$/', $jid) !== 1) {
                continue;
            }
            $thread = is_array($row['thread_metadata'] ?? null) ? $row['thread_metadata'] : [];
            $name = $thread['name']['text'] ?? $row['name'] ?? $row['Name'] ?? null;
            $viewer = is_array($row['viewer_metadata'] ?? null) ? $row['viewer_metadata'] : [];
            $role = $viewer['role'] ?? $row['role'] ?? null;
            $role = is_string($role) ? strtolower($role) : null;
            $channels[] = new ChannelSummary(
                $jid,
                is_string($name) ? mb_substr(trim($name), 0, 120) : '',
                match ($role) {
                    'owner', 'admin' => true,
                    'subscriber', 'guest' => false,
                    default => null,
                },
            );
        }

        return $channels;
    }

    /**
     * Fatos do grupo. Campo ausente = desconhecido (null). A documentação avisa que OwnerIsAdmin e invite_link
     * podem faltar mesmo quando existem/são falsos, por isso ausência nunca vira "não" nem "sim".
     *
     * @param array<array-key, mixed> $row
     */
    private static function group(array $row): ?GroupSummary
    {
        if (!is_string($row['JID'] ?? null) || preg_match('/^[0-9-]{5,64}@g\.us$/', $row['JID']) !== 1) {
            return null;
        }
        $bool = static fn (string $key): ?bool => is_bool($row[$key] ?? null) ? $row[$key] : null;
        $parent = $bool('IsParent');
        $defaultSub = $bool('IsDefaultSubGroup');
        $community = $parent === true || $defaultSub === true ? true : ($parent === false && $defaultSub === false ? false : null);
        $invite = $row['invite_link'] ?? null;

        return new GroupSummary(
            $row['JID'],
            is_string($row['Name'] ?? null) ? mb_substr(trim($row['Name']), 0, 120) : '',
            $bool('OwnerIsAdmin'),
            $bool('IsJoinApprovalRequired'),
            $bool('IsAnnounce'),
            is_int($row['ParticipantCount'] ?? null) ? $row['ParticipantCount'] : null,
            $community,
            // Só registramos SE existe link (fato); o link em si não é guardado nem acessado.
            is_string($invite) && parse_url($invite, PHP_URL_SCHEME) === 'https' && parse_url($invite, PHP_URL_HOST) === 'chat.whatsapp.com' ? true : null,
        );
    }

    public function sendImage(SensitiveValue $instanceToken, string $chatId, string $imageUrl, string $caption): SentMessage
    {
        if (preg_match('/^[0-9A-Za-z._-]+@(g\.us|newsletter|s\.whatsapp\.net)$/', $chatId) !== 1 || !str_starts_with($imageUrl, 'https://')) {
            throw new \InvalidArgumentException('Destino ou imagem inválidos.');
        }
        $json = $this->send('send_media', 'POST', '/send/media', ['token' => $instanceToken], [
            'number' => $chatId, 'type' => 'image', 'file' => $imageUrl, 'text' => $caption,
        ]);
        $id = $json['messageid'] ?? $json['id'] ?? null;

        return new SentMessage(is_string($id) && $id !== '' ? mb_substr($id, 0, 128) : null);
    }

    /** @param array<array-key, mixed> $json */
    private function snapshot(string $operation, array $json): ConnectionSnapshot
    {
        $instance = is_array($json['instance'] ?? null) ? $json['instance'] : [];
        $status = is_array($json['status'] ?? null) ? $json['status'] : [];
        $state = $instance['status'] ?? null;
        if (!is_string($state) || !in_array($state, self::STATES, true)) {
            $connected = $status['connected'] ?? $json['connected'] ?? null;
            if (!is_bool($connected)) {
                throw new Failure(Failure::INVALID_RESPONSE, 200, null, $operation);
            }
            $state = $connected ? ConnectionSnapshot::CONNECTED : ConnectionSnapshot::DISCONNECTED;
        }

        $qr = $instance['qrcode'] ?? null;
        $qr = is_string($qr) && strlen($qr) < 200_000 && preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]{20,}$#', $qr) === 1 ? $qr : null;
        $pair = $instance['paircode'] ?? null;
        $pair = is_string($pair) && preg_match('/^[A-Z0-9]{4}-?[A-Z0-9]{4}$/i', $pair) === 1 ? strtoupper($pair) : null;
        $jid = $status['jid'] ?? $json['jid'] ?? null;
        $user = is_array($jid) ? ($jid['user'] ?? null) : (is_string($jid) ? strstr($jid, '@', true) : null);
        $profile = $instance['profileName'] ?? null;

        return new ConnectionSnapshot(
            $state,
            $state === ConnectionSnapshot::CONNECTING ? $qr : null,
            $state === ConnectionSnapshot::CONNECTING ? $pair : null,
            is_string($user) && preg_match('/^\d{8,15}$/', $user) === 1 ? $user : null,
            is_string($profile) && trim($profile) !== '' ? mb_substr(trim($profile), 0, 120) : null,
        );
    }

    /**
     * @param array<string, SensitiveValue>       $auth cabeçalho → segredo
     * @param array<string, mixed>|\stdClass|null $body
     *
     * @return array<array-key, mixed>
     */
    private function send(string $operation, string $method, string $path, array $auth, array|\stdClass|null $body = null): array
    {
        $request = $this->requests->createRequest($method, $this->config->baseUrl . $path)->withHeader('Accept', 'application/json');
        foreach ($auth as $header => $secret) {
            $request = $request->withHeader($header, $secret->reveal());
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }

        $started = hrtime(true);
        try {
            $response = $this->http->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            $code = stripos($e->getMessage(), 'timed out') !== false || str_contains($e->getMessage(), 'cURL error 28')
                ? Failure::TIMEOUT : Failure::NETWORK_ERROR;
            $this->log($operation, null, $started, $code);
            throw new Failure($code, null, null, $operation);
        } catch (ClientExceptionInterface) {
            $this->log($operation, null, $started, Failure::NETWORK_ERROR);
            throw new Failure(Failure::NETWORK_ERROR, null, null, $operation);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $code = match (true) {
                $status === 401 => Failure::UNAUTHORIZED,
                $status === 403 => Failure::FORBIDDEN,
                $status === 404 => Failure::NOT_FOUND,
                $status === 409 => Failure::CONFLICT,
                $status === 429 => Failure::RATE_LIMITED,
                $status >= 500 => Failure::SERVER_ERROR,
                default => Failure::HTTP_ERROR,
            };
            $retry = $response->getHeaderLine('Retry-After');
            $this->log($operation, $status, $started, $code);
            throw new Failure($code, $status, ctype_digit($retry) ? min((int) $retry, 3600) : null, $operation);
        }

        $json = json_decode((string) $response->getBody(), true);
        if (!is_array($json)) {
            $this->log($operation, $status, $started, Failure::INVALID_RESPONSE);
            throw new Failure(Failure::INVALID_RESPONSE, $status, null, $operation);
        }
        $this->log($operation, $status, $started, null);

        return $json;
    }

    private function log(string $operation, ?int $status, int|float $started, ?string $error): void
    {
        $this->logger->log($error === null ? 'info' : 'warning', 'whatsapp.request', [
            'provider' => 'uazapi',
            'operation' => $operation,
            'http_status' => $status,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'error' => $error,
        ]);
    }
}
