<?php

declare(strict_types=1);

namespace Sinergia\Application\WhatsApp;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Port\WhatsApp\ConnectionSnapshot;
use Sinergia\Application\Port\WhatsApp\WhatsAppConnectionStore;
use Sinergia\Application\Port\WhatsApp\WhatsAppProvider;
use Sinergia\Application\Port\WhatsApp\WhatsAppProviderFailure;
use Sinergia\Shared\Clock\Clock;

/**
 * Conexão WhatsApp da conta AUTENTICADA (a conta vem só do TenantContext).
 * - Uma instância por conta; criar/conectar/desconectar acontece sob trava da conta (clique duplo e concorrência).
 * - QR/código de pareamento valem por CONNECT_WINDOW_SECONDS; depois disso nada antigo é exibido.
 * - Nenhum token é devolvido para a camada web.
 * - Com $autoCreateInstances = false (Evolution API, opção C) o BotML NUNCA cria instância: a conta precisa de uma
 *   instância atribuída pelo administrador; sem ela, WhatsAppNotProvisioned ("ainda não liberado").
 */
final class ManageWhatsAppConnection
{
    public const string PROVIDER = 'uazapi';
    public const int CONNECT_WINDOW_SECONDS = 180;
    public const int STATUS_REFRESH_SECONDS = 60;

    public const string STARTED = 'started';
    public const string ALREADY_CONNECTING = 'already_connecting';
    public const string ALREADY_CONNECTED = 'already_connected';

    /** @param \Closure(): WhatsAppProvider $provider resolvido só quando há chamada ao provedor */
    public function __construct(
        private readonly WhatsAppConnectionStore $store,
        private readonly \Closure $provider,
        private readonly bool $available,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $providerName = self::PROVIDER,
        private readonly bool $autoCreateInstances = true,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * @throws WhatsAppProviderFailure
     * @throws WhatsAppBusy
     */
    public function connect(TenantContext $tenant, ?string $phone = null): string
    {
        $this->assertAvailable();
        $installation = $tenant->installationId;
        try {
            $outcome = $this->store->withLock($installation, function () use ($tenant, $installation, $phone): string {
                $now = $this->clock->now();
                if (!$this->autoCreateInstances) {
                    $existing = $this->store->find($installation);
                    if ($existing === null || $existing->token === null) {
                        throw new WhatsAppNotProvisioned('WhatsApp ainda não está liberado para esta conta.');
                    }
                }
                $this->store->ensure($installation, $this->providerName, self::newInstanceName($tenant), $tenant->userId, $now);
                $current = $this->store->find($installation) ?? throw new \LogicException('Conexão não gravada.');

                if ($current->status === 'connecting' && $current->connectStartedAt !== null && $this->age($current->connectStartedAt) < self::CONNECT_WINDOW_SECONDS) {
                    return self::ALREADY_CONNECTING;
                }
                if ($current->status === 'connected') {
                    return self::ALREADY_CONNECTED;
                }

                $provider = ($this->provider)();
                $token = $current->token;
                $created = false;
                if ($token === null) {
                    $instance = $provider->createInstance($current->instanceName);
                    $this->store->storeInstance($installation, $instance, $tenant->userId, $now);
                    $token = $instance->token;
                    $created = true;
                }

                try {
                    $snapshot = $provider->connect($token, $phone);
                } catch (WhatsAppProviderFailure $e) {
                    if (!$created && !$this->autoCreateInstances && in_array($e->errorCode, [WhatsAppProviderFailure::UNAUTHORIZED, WhatsAppProviderFailure::NOT_FOUND], true)) {
                        // Instância atribuída foi removida/invalidada no provedor: esquece a credencial e NÃO cria outra.
                        $this->store->clearInstance($installation, self::newInstanceName($tenant), $now);
                        $this->logger->warning('whatsapp.instance_invalidated', ['installation_id' => $installation->value, 'error' => $e->errorCode]);

                        throw new WhatsAppNotProvisioned('WhatsApp ainda não está liberado para esta conta.');
                    }
                    if (!$created && in_array($e->errorCode, [WhatsAppProviderFailure::UNAUTHORIZED, WhatsAppProviderFailure::NOT_FOUND], true)) {
                        // Instância removida/invalidada no provedor: cria UMA nova para esta conta e tenta uma vez.
                        $name = self::newInstanceName($tenant);
                        $this->store->clearInstance($installation, $name, $now);
                        $instance = $provider->createInstance($name);
                        $this->store->storeInstance($installation, $instance, $tenant->userId, $now);
                        $snapshot = $provider->connect($instance->token, $phone);
                        $this->logger->info('whatsapp.instance_recreated', ['installation_id' => $installation->value]);
                    } elseif ($e->errorCode === WhatsAppProviderFailure::CONFLICT) {
                        // Já existe fluxo de conexão no provedor: apenas acompanha.
                        $snapshot = $provider->status($token);
                    } else {
                        throw $e;
                    }
                }

                $this->store->markConnecting($installation, $phone === null ? 'qr' : 'paircode', $tenant->userId, $now);
                $this->store->recordSnapshot($installation, $snapshot, $now);

                return $snapshot->isConnected() ? self::ALREADY_CONNECTED : self::STARTED;
            });
        } catch (WhatsAppProviderFailure $e) {
            $this->fail($tenant, 'connect', $e);
        }

        $this->logger->info('whatsapp.connect', ['installation_id' => $installation->value, 'user_id' => $tenant->userId, 'outcome' => $outcome, 'mode' => $phone === null ? 'qr' : 'paircode']);

        return $outcome;
    }

    /**
     * @throws WhatsAppProviderFailure
     * @throws WhatsAppBusy
     */
    public function disconnect(TenantContext $tenant): void
    {
        $this->assertAvailable();
        $installation = $tenant->installationId;
        try {
            $this->store->withLock($installation, function () use ($tenant, $installation): void {
                $now = $this->clock->now();
                $current = $this->store->find($installation);
                if ($current === null) {
                    return;
                }
                if ($current->token !== null) {
                    try {
                        ($this->provider)()->disconnect($current->token);
                    } catch (WhatsAppProviderFailure $e) {
                        if (!in_array($e->errorCode, [WhatsAppProviderFailure::UNAUTHORIZED, WhatsAppProviderFailure::NOT_FOUND], true)) {
                            throw $e;
                        }
                        // A instância já não existe no provedor: esquece o token desta conta.
                        $this->store->clearInstance($installation, self::newInstanceName($tenant), $now);
                    }
                }
                $this->store->markDisconnected($installation, $tenant->userId, $now);
            });
        } catch (WhatsAppProviderFailure $e) {
            $this->fail($tenant, 'disconnect', $e);
        }
        $this->logger->info('whatsapp.disconnect', ['installation_id' => $installation->value, 'user_id' => $tenant->userId]);
    }

    /** Estado atual do card. Consulta o provedor quando há QR em andamento ou o status conectado está velho. */
    public function card(TenantContext $tenant): WhatsAppCard
    {
        if (!$this->available) {
            return new WhatsAppCard(WhatsAppCard::UNAVAILABLE);
        }
        $installation = $tenant->installationId;
        $current = $this->store->find($installation);
        if (!$this->autoCreateInstances && ($current === null || $current->token === null)) {
            return new WhatsAppCard(WhatsAppCard::NOT_PROVISIONED);
        }
        if ($current === null || ($current->token === null && $current->lastErrorCode === null)) {
            return new WhatsAppCard(WhatsAppCard::NOT_CONNECTED);
        }
        if ($current->lastErrorCode !== null && $current->status !== 'connected') {
            return new WhatsAppCard(WhatsAppCard::ERROR, errorCode: $current->lastErrorCode);
        }
        $token = $current->token ?? throw new \LogicException('Conexão sem token.');

        $live = $current->status === 'connecting'
            || ($current->status === 'connected' && ($current->statusCheckedAt === null || $this->age($current->statusCheckedAt) >= self::STATUS_REFRESH_SECONDS));
        if ($live) {
            try {
                $snapshot = ($this->provider)()->status($token);
            } catch (WhatsAppProviderFailure $e) {
                $this->logger->warning('whatsapp.status_failed', ['installation_id' => $installation->value, 'error' => $e->errorCode, 'http_status' => $e->httpStatus]);

                return $current->status === 'connected'
                    ? new WhatsAppCard(WhatsAppCard::CONNECTED, phoneDisplay: $current->phoneDisplay, profileName: $current->profileName, connectedAt: $current->connectedAt, errorCode: $e->errorCode)
                    : new WhatsAppCard(WhatsAppCard::ERROR, errorCode: $e->errorCode);
            }
            $this->store->recordSnapshot($installation, $snapshot, $this->clock->now());
            $current = $this->store->find($installation) ?? $current;

            if ($snapshot->state === ConnectionSnapshot::CONNECTING) {
                $left = $current->connectStartedAt === null ? 0 : self::CONNECT_WINDOW_SECONDS - $this->age($current->connectStartedAt);
                if ($left > 0) {
                    return new WhatsAppCard(WhatsAppCard::WAITING, $snapshot->qrCode, $snapshot->pairCode, $left);
                }

                return new WhatsAppCard(WhatsAppCard::EXPIRED);
            }
        }

        return match (true) {
            $current->status === 'connected' => new WhatsAppCard(WhatsAppCard::CONNECTED, phoneDisplay: $current->phoneDisplay, profileName: $current->profileName, connectedAt: $current->connectedAt),
            // Um fluxo de conexão foi iniciado e terminou sem conectar (QR expirado ou não lido).
            $current->status === 'disconnected' && $current->connectStartedAt !== null => new WhatsAppCard(WhatsAppCard::EXPIRED),
            $current->status === 'new' => new WhatsAppCard(WhatsAppCard::NOT_CONNECTED),
            $current->status === 'disconnected' && $current->connectedAt === null => new WhatsAppCard(WhatsAppCard::NOT_CONNECTED),
            default => new WhatsAppCard(WhatsAppCard::DISCONNECTED),
        };
    }

    private function assertAvailable(): void
    {
        if (!$this->available) {
            throw new WhatsAppUnavailable('Integração WhatsApp não configurada neste servidor.');
        }
    }

    /** @throws WhatsAppProviderFailure */
    private function fail(TenantContext $tenant, string $operation, WhatsAppProviderFailure $e): never
    {
        $this->store->recordError($tenant->installationId, $e->errorCode, $this->clock->now());
        $this->logger->warning('whatsapp.' . $operation . '_failed', [
            'installation_id' => $tenant->installationId->value,
            'error' => $e->errorCode,
            'http_status' => $e->httpStatus,
            'provider_operation' => $e->operation,
        ]);
        throw $e;
    }

    private function age(\DateTimeImmutable $at): int
    {
        return $this->clock->now()->getTimestamp() - $at->getTimestamp();
    }

    /** Nome sem dados pessoais: prefixo + conta + sufixo aleatório (único no provedor). */
    private static function newInstanceName(TenantContext $tenant): string
    {
        return 'sbm-' . $tenant->installationId->value . '-' . bin2hex(random_bytes(4));
    }
}
