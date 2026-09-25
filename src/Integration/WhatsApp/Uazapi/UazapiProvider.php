<?php

declare(strict_types=1);

namespace Sinergia\Integration\WhatsApp\Uazapi;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
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
 *   POST /group/list           (token)      → etapa 6
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
            if (!is_array($row) || !is_string($row['JID'] ?? null) || !str_ends_with($row['JID'], '@g.us')) {
                continue;
            }
            $groups[] = new GroupSummary(
                $row['JID'],
                is_string($row['Name'] ?? null) ? mb_substr($row['Name'], 0, 120) : '',
                is_bool($row['OwnerIsAdmin'] ?? null) ? $row['OwnerIsAdmin'] : null,
                is_bool($row['IsJoinApprovalRequired'] ?? null) ? $row['IsJoinApprovalRequired'] : null,
                is_bool($row['IsAnnounce'] ?? null) ? $row['IsAnnounce'] : null,
                is_int($row['ParticipantCount'] ?? null) ? $row['ParticipantCount'] : null,
            );
        }
        $total = $json['pagination']['totalRecords'] ?? null;

        return new GroupPage($groups, is_int($total) ? $total : null);
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
