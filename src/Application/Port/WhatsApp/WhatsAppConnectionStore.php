<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\WhatsApp;

use Sinergia\Application\WhatsApp\WhatsAppBusy;
use Sinergia\Application\WhatsApp\WhatsAppConnection;
use Sinergia\Domain\Installation\InstallationId;

/** Conexão WhatsApp por conta. TODA leitura/escrita é restrita ao installation_id recebido. */
interface WhatsAppConnectionStore
{
    /**
     * Executa $work com trava exclusiva DA CONTA (evita duas instâncias por clique duplo ou requisições simultâneas).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws WhatsAppBusy quando outra operação da mesma conta segura a trava
     * @throws \Throwable   repassa, sem alterar, o que $work lançar (ex.: WhatsAppProviderFailure)
     */
    public function withLock(InstallationId $installation, callable $work): mixed;

    public function find(InstallationId $installation): ?WhatsAppConnection;

    /** Cria a linha da conta (sem instância) se ainda não existir. */
    public function ensure(InstallationId $installation, string $provider, string $instanceName, int $userId, \DateTimeImmutable $now): void;

    public function storeInstance(InstallationId $installation, ProviderInstance $instance, int $userId, \DateTimeImmutable $now): void;

    /** Esquece a instância (token inválido/instância removida no provedor). */
    public function clearInstance(InstallationId $installation, string $newInstanceName, \DateTimeImmutable $now): void;

    public function markConnecting(InstallationId $installation, string $mode, int $userId, \DateTimeImmutable $now): void;

    public function recordSnapshot(InstallationId $installation, ConnectionSnapshot $snapshot, \DateTimeImmutable $now): void;

    public function markDisconnected(InstallationId $installation, ?int $userId, \DateTimeImmutable $now): void;

    public function recordError(InstallationId $installation, string $code, \DateTimeImmutable $now): void;
}
