<?php

declare(strict_types=1);

namespace Sinergia\Integration\WhatsApp\Evolution;

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
use Sinergia\Shared\Config\EvolutionConfig;
use Sinergia\Shared\Config\SensitiveValue;

/**
 * Evolution API self-hosted — implementação validada contra a versão 2.3.7 (tag oficial 2.3.7, Baileys 7.0.0-rc.9).
 * Versões 2.4.x estão FORA do escopo (mudanças de licenciamento/ativação).
 *
 * Opção C: o BotML NUNCA conhece a chave global da Evolution. Cada conta recebe, por atribuição administrativa
 * (bin/console whatsapp:instance:assign), a credencial da PRÓPRIA instância ("evo1:<nome>:<token>", ver
 * EvolutionCredential). Toda chamada usa só o cabeçalho "apikey" = token da instância, que a 2.3.7 aceita em
 * qualquer rota com /{instanceName} e em /instance/fetchInstances (filtrado pelo token).
 *
 *   GET    /instance/connect/{n}[?number=]      → QR (base64 data:image/png) ou pairingCode; se já conectada, o estado
 *   GET    /instance/connectionState/{n}        → instance.state = open | connecting | close
 *   GET    /instance/fetchInstances             → ownerJid / profileName (só para o card; falha não derruba o status)
 *   DELETE /instance/logout/{n}                 → encerra a sessão (instância e token continuam válidos)
 *   GET    /group/fetchAllGroups/{n}?getParticipants=false
 *   GET    /group/findGroupInfos/{n}?groupJid=  → participants[] (id, phoneNumber, lid, admin)
 *   GET    /group/inviteCode/{n}?groupJid=      → inviteUrl (só admin consegue: sucesso = nosso número é admin)
 *   GET    /group/inviteInfo/{n}?inviteCode=    → metadados brutos do Baileys, com joinApprovalMode
 *   POST   /message/sendMedia/{n}               → {number, mediatype: image, media: URL, caption}
 * Canais (newsletter): a 2.3.7 não tem rota — listChannels() devolve lista vazia (canais fora do F1).
 * Nenhum token vai para URL, log, exceção ou HTML; o texto das respostas de erro da Evolution nunca é repassado.
 */
final class EvolutionProvider implements WhatsAppProvider
{
    private const array STATES = [
        'open' => ConnectionSnapshot::CONNECTED,
        'connecting' => ConnectionSnapshot::CONNECTING,
        'close' => ConnectionSnapshot::DISCONNECTED,
    ];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly EvolutionConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Opção C: a instância é criada FORA do BotML, por um administrador, com a chave global da Evolution.
     * O BotML nunca cria instância; ManageWhatsAppConnection não chama este método com a Evolution.
     */
    public function createInstance(string $name): ProviderInstance
    {
        throw new Failure(Failure::FORBIDDEN, null, null, 'create');
    }

    public function connect(SensitiveValue $instanceToken, ?string $phone = null): ConnectionSnapshot
    {
        $credential = $this->credential($instanceToken, 'connect');
        if ($phone !== null && preg_match('/^\d{10,15}$/D', $phone) !== 1) {
            throw new \InvalidArgumentException('Telefone inválido.');
        }
        $json = $this->send('connect', 'GET', '/instance/connect/' . $credential->instanceName, $credential, $phone === null ? [] : ['number' => $phone]);

        // Já conectada: a 2.3.7 devolve o connectionState no lugar do QR.
        if (is_array($json['instance'] ?? null)) {
            return $this->snapshot($credential, $json, 'connect');
        }

        return $this->qrSnapshot($json);
    }

    public function status(SensitiveValue $instanceToken): ConnectionSnapshot
    {
        $credential = $this->credential($instanceToken, 'status');
        $json = $this->send('status', 'GET', '/instance/connectionState/' . $credential->instanceName, $credential);

        return $this->snapshot($credential, $json, 'status');
    }

    public function disconnect(SensitiveValue $instanceToken): ConnectionSnapshot
    {
        $credential = $this->credential($instanceToken, 'disconnect');
        try {
            $this->send('disconnect', 'DELETE', '/instance/logout/' . $credential->instanceName, $credential);
        } catch (Failure $e) {
            // 400 = "instance is not connected": já está desconectada (logout idempotente).
            if ($e->httpStatus !== 400) {
                throw $e;
            }
        }

        return new ConnectionSnapshot(ConnectionSnapshot::DISCONNECTED);
    }

    /** A 2.3.7 devolve todos os grupos de uma vez (sem paginação): página 0 traz tudo, as demais vêm vazias. */
    public function listGroups(SensitiveValue $instanceToken, int $limit, int $offset): GroupPage
    {
        $credential = $this->credential($instanceToken, 'group_list');
        if ($offset > 0) {
            return new GroupPage([], null);
        }
        $json = $this->send('group_list', 'GET', '/group/fetchAllGroups/' . $credential->instanceName, $credential, ['getParticipants' => 'false']);
        if (!array_is_list($json)) {
            throw new Failure(Failure::INVALID_RESPONSE, 200, null, 'group_list');
        }
        $groups = [];
        foreach ($json as $row) {
            $group = is_array($row) ? self::group($row, null, null, null) : null;
            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return new GroupPage($groups, count($groups));
    }

    /**
     * Fatos de UM grupo: findGroupInfos (dados gerais) + inviteCode (link; só admin obtém) + inviteInfo
     * (joinApprovalMode). Dado que a Evolution não informar fica null (desconhecido) — nunca vira "sim" ou "não".
     */
    public function groupInfo(SensitiveValue $instanceToken, string $groupJid): GroupSummary
    {
        if (preg_match('/^[0-9-]{5,64}@g\.us$/D', $groupJid) !== 1) {
            throw new \InvalidArgumentException('JID de grupo inválido.');
        }
        $credential = $this->credential($instanceToken, 'group_info');
        $name = $credential->instanceName;
        $info = $this->send('group_info', 'GET', '/group/findGroupInfos/' . $name, $credential, ['groupJid' => $groupJid]);
        if (($info['id'] ?? null) !== $groupJid) {
            throw new Failure(Failure::INVALID_RESPONSE, 200, null, 'group_info');
        }

        $isAdmin = null;
        $hasInvite = null;
        $joinApproval = null;
        $code = $this->inviteCode($credential, $groupJid);
        if ($code !== null) {
            // O WhatsApp só entrega o código de convite a administradores: obtê-lo prova que somos admin.
            $isAdmin = true;
            $hasInvite = true;
            $joinApproval = $this->joinApproval($credential, $code);
        } else {
            $isAdmin = $this->adminFromParticipants($credential, $info['participants'] ?? null);
        }

        return self::group($info, $isAdmin, $joinApproval, $hasInvite) ?? throw new Failure(Failure::INVALID_RESPONSE, 200, null, 'group_info');
    }

    /** Canais (newsletter) não existem na Evolution 2.3.7; ficam fora do F1. */
    public function listChannels(SensitiveValue $instanceToken): array
    {
        return [];
    }

    public function sendImage(SensitiveValue $instanceToken, string $chatId, string $imageUrl, string $caption): SentMessage
    {
        if (preg_match('/^[0-9A-Za-z._-]+@(g\.us|s\.whatsapp\.net)$/D', $chatId) !== 1 || !str_starts_with($imageUrl, 'https://')) {
            throw new \InvalidArgumentException('Destino ou imagem inválidos.');
        }
        $credential = $this->credential($instanceToken, 'send_media');
        $json = $this->send('send_media', 'POST', '/message/sendMedia/' . $credential->instanceName, $credential, [], [
            'number' => $chatId, 'mediatype' => 'image', 'media' => $imageUrl, 'caption' => $caption,
        ]);
        $key = is_array($json['key'] ?? null) ? $json['key'] : [];
        $id = $key['id'] ?? null;

        return new SentMessage(is_string($id) && $id !== '' ? mb_substr($id, 0, 128) : null);
    }

    /** @return ?string código de convite, ou null quando a Evolution não o entrega (não somos admin) */
    private function inviteCode(EvolutionCredential $credential, string $groupJid): ?string
    {
        try {
            $json = $this->send('group_invite_code', 'GET', '/group/inviteCode/' . $credential->instanceName, $credential, ['groupJid' => $groupJid]);
        } catch (Failure $e) {
            // 2.3.7: sem permissão o Baileys falha e a Evolution responde 404 "No invite code" (ou 400/403).
            if (in_array($e->httpStatus, [400, 403, 404], true)) {
                return null;
            }
            throw $e;
        }
        $code = $json['inviteCode'] ?? null;
        $url = $json['inviteUrl'] ?? null;
        $validUrl = is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'chat.whatsapp.com';

        return is_string($code) && preg_match('/^[A-Za-z0-9]{6,64}$/D', $code) === 1 && $validUrl ? $code : null;
    }

    private function joinApproval(EvolutionCredential $credential, string $code): ?bool
    {
        try {
            $json = $this->send('group_invite_info', 'GET', '/group/inviteInfo/' . $credential->instanceName, $credential, ['inviteCode' => $code]);
        } catch (Failure $e) {
            if (in_array($e->httpStatus, [400, 403, 404], true)) {
                return null;
            }
            throw $e;
        }

        return is_bool($json['joinApprovalMode'] ?? null) ? $json['joinApprovalMode'] : null;
    }

    /**
     * Sem código de convite: procura o nosso número na lista de participantes. Participante com endereço LID sem
     * número de telefone não é identificável → null (desconhecido).
     */
    private function adminFromParticipants(EvolutionCredential $credential, mixed $participants): ?bool
    {
        if (!is_array($participants) || !array_is_list($participants)) {
            return null;
        }
        $owner = $this->ownerJid($credential);
        if ($owner === null) {
            return null;
        }
        foreach ($participants as $participant) {
            if (!is_array($participant)) {
                continue;
            }
            if (($participant['id'] ?? null) === $owner || ($participant['phoneNumber'] ?? null) === $owner) {
                return in_array($participant['admin'] ?? null, ['admin', 'superadmin'], true);
            }
        }

        return null;
    }

    private function ownerJid(EvolutionCredential $credential): ?string
    {
        $info = $this->instanceInfo($credential);
        $jid = $info['ownerJid'] ?? null;

        return is_string($jid) && preg_match('/^\d{8,15}@s\.whatsapp\.net$/D', $jid) === 1 ? $jid : null;
    }

    /**
     * Dados da própria instância via fetchInstances (token da instância). Usado só para enriquecer:
     * qualquer falha devolve [] e não derruba a operação principal.
     *
     * @return array<array-key, mixed>
     */
    private function instanceInfo(EvolutionCredential $credential): array
    {
        try {
            $json = $this->send('fetch_instance', 'GET', '/instance/fetchInstances', $credential, ['instanceName' => $credential->instanceName]);
        } catch (Failure) {
            return [];
        }
        foreach (array_is_list($json) ? $json : [$json] as $row) {
            if (is_array($row) && ($row['name'] ?? $row['instanceName'] ?? null) === $credential->instanceName) {
                return $row;
            }
        }

        return [];
    }

    /** @param array<array-key, mixed> $json */
    private function snapshot(EvolutionCredential $credential, array $json, string $operation): ConnectionSnapshot
    {
        $instance = is_array($json['instance'] ?? null) ? $json['instance'] : [];
        $state = self::STATES[$instance['state'] ?? ''] ?? null;
        if ($state === null) {
            throw new Failure(Failure::INVALID_RESPONSE, 200, null, $operation);
        }
        if ($state === ConnectionSnapshot::CONNECTING) {
            // connectionState não traz o QR; na 2.3.7, /instance/connect com a instância "connecting" só devolve o QR atual.
            return $this->qrSnapshot($this->send('connect', 'GET', '/instance/connect/' . $credential->instanceName, $credential));
        }
        if ($state === ConnectionSnapshot::CONNECTED) {
            $info = $this->instanceInfo($credential);
            $jid = $info['ownerJid'] ?? null;
            $phone = is_string($jid) && preg_match('/^(\d{8,15})@s\.whatsapp\.net$/', $jid, $m) === 1 ? $m[1] : null;
            $profile = $info['profileName'] ?? null;

            return new ConnectionSnapshot($state, null, null, $phone, is_string($profile) && trim($profile) !== '' ? mb_substr(trim($profile), 0, 120) : null);
        }

        return new ConnectionSnapshot($state);
    }

    /** @param array<array-key, mixed> $json resposta de /instance/connect: {pairingCode, code, base64, count} */
    private function qrSnapshot(array $json): ConnectionSnapshot
    {
        $qr = $json['base64'] ?? null;
        $qr = is_string($qr) && strlen($qr) < 200_000 && preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]{20,}$#', $qr) === 1 ? $qr : null;
        $pair = $json['pairingCode'] ?? null;
        $pair = is_string($pair) && preg_match('/^[A-Z0-9]{4}-?[A-Z0-9]{4}$/i', $pair) === 1 ? strtoupper($pair) : null;

        return new ConnectionSnapshot(ConnectionSnapshot::CONNECTING, $qr, $pair);
    }

    /**
     * Fatos do grupo no formato da 2.3.7 (id, subject, size, announce, isCommunity, isCommunityAnnounce, linkedParent).
     *
     * @param array<array-key, mixed> $row
     */
    private static function group(array $row, ?bool $isAdmin, ?bool $joinApproval, ?bool $hasInvite): ?GroupSummary
    {
        $jid = $row['id'] ?? null;
        if (!is_string($jid) || preg_match('/^[0-9-]{5,64}@g\.us$/D', $jid) !== 1) {
            return null;
        }
        $community = $row['isCommunity'] ?? null;
        $announceGroup = $row['isCommunityAnnounce'] ?? null;
        $parent = $row['linkedParent'] ?? null;
        $isCommunity = match (true) {
            $community === true || $announceGroup === true || (is_string($parent) && $parent !== '') => true,
            $community === false && $announceGroup === false && ($parent === null || $parent === '') && array_key_exists('linkedParent', $row) => false,
            default => null,
        };
        $subject = $row['subject'] ?? null;

        return new GroupSummary(
            $jid,
            is_string($subject) ? mb_substr(trim($subject), 0, 120) : '',
            $isAdmin,
            $joinApproval,
            is_bool($row['announce'] ?? null) ? $row['announce'] : null,
            is_int($row['size'] ?? null) ? $row['size'] : null,
            $isCommunity,
            $hasInvite,
        );
    }

    private function credential(SensitiveValue $instanceToken, string $operation): EvolutionCredential
    {
        try {
            return EvolutionCredential::parse($instanceToken);
        } catch (\InvalidArgumentException) {
            // Credencial gravada inválida: trata como autenticação recusada (sem chamar a Evolution e sem citar o valor).
            $this->log($operation, null, hrtime(true), 'invalid_credential');
            throw new Failure(Failure::UNAUTHORIZED, null, null, $operation);
        }
    }

    /**
     * @param array<string, string>     $query parâmetros NÃO sensíveis (groupJid, inviteCode, number, flags)
     * @param array<string, mixed>|null $body
     *
     * @return array<array-key, mixed>
     */
    private function send(string $operation, string $method, string $path, EvolutionCredential $credential, array $query = [], ?array $body = null): array
    {
        $uri = $this->config->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $request = $this->requests->createRequest($method, $uri)
            ->withHeader('Accept', 'application/json')
            ->withHeader('apikey', $credential->token->reveal());
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
            'provider' => 'evolution',
            'operation' => $operation,
            'http_status' => $status,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'error' => $error,
        ]);
    }
}
