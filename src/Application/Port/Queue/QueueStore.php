<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Queue;

use Sinergia\Application\Queue\ClaimedItem;
use Sinergia\Application\Queue\PlannerCandidate;
use Sinergia\Application\Queue\PlannerDestination;
use Sinergia\Domain\Installation\InstallationId;

/**
 * Fila por conta. TODA leitura/escrita recebe e filtra por installation_id; o único método sem conta é
 * activeInstallations(), usado pelo worker para saber quais contas processar.
 */
interface QueueStore
{
    /** @return list<InstallationId> contas com bot ativo */
    public function activeInstallations(): array;

    /**
     * Executa $work com a trava da conta (GET_LOCK sem espera). null = outra instância do worker já processa a conta.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T|null
     */
    public function withInstallationLock(InstallationId $installation, callable $work): mixed;

    public function botActive(InstallationId $installation): bool;

    public function setBotStatus(InstallationId $installation, bool $active, int $userId, \DateTimeImmutable $now): void;

    public function timezone(InstallationId $installation): string;

    public function revalidationDue(InstallationId $installation, \DateTimeImmutable $now, int $everySeconds): bool;

    public function markRevalidated(InstallationId $installation, \DateTimeImmutable $now): void;

    public function lastSelectionAt(InstallationId $installation): ?\DateTimeImmutable;

    /** @return list<PlannerDestination> */
    public function plannerDestinations(InstallationId $installation): array;

    /** @return list<PlannerCandidate> */
    public function plannerCandidates(InstallationId $installation): array;

    /** @return array<string, true> produtos já usados no destino (qualquer status) */
    public function usedProducts(InstallationId $installation, int $destinationId): array;

    public function activeLinkId(InstallationId $installation, string $productId): ?int;

    /** false = já existia (UNIQUE destino × produto). */
    public function insertItem(InstallationId $installation, int $destinationId, PlannerCandidate $candidate, string $status, ?int $linkId, \DateTimeImmutable $scheduledFor, \DateTimeImmutable $now): bool;

    /** Itens aguardando link cujo produto ganhou link ativo DA CONTA → agendado (auto) ou aguardando aprovação (manual). */
    public function promoteAwaiting(InstallationId $installation, \DateTimeImmutable $now): int;

    /** @return list<int> itens 'scheduled' vencidos de destinos ativos, do mais antigo para o mais novo */
    public function dueItemIds(InstallationId $installation, \DateTimeImmutable $now, int $limit): array;

    /** Reserva atômica: scheduled → sending com claim_token. true só para UM worker. */
    public function claim(InstallationId $installation, int $id, string $token, \DateTimeImmutable $now): bool;

    public function loadClaimed(InstallationId $installation, int $id, string $token): ?ClaimedItem;

    /** Devolve para 'scheduled' (não enviado), com novo horário e/ou próxima tentativa. */
    public function release(InstallationId $installation, int $id, string $token, \DateTimeImmutable $scheduledFor, ?\DateTimeImmutable $nextAttemptAt, ?string $error, \DateTimeImmutable $now): void;

    /** Encerra sem envio: skipped | failed | awaiting_affiliate_link | pending_approval | cancelled. */
    public function finish(InstallationId $installation, int $id, string $token, string $status, ?string $error, \DateTimeImmutable $now): void;

    /**
     * Marca o início REAL do envio (a partir daqui o resultado pode ser incerto) e grava a mensagem e o preço atual.
     *
     * @param array<string, mixed> $message
     */
    public function markSendStarted(InstallationId $installation, int $id, string $token, int $linkId, array $message, int $priceCents, ?int $originalCents, int $discount, \DateTimeImmutable $now): bool;

    public function markSent(InstallationId $installation, int $id, string $token, ?string $providerMessageId, \DateTimeImmutable $now): void;

    public function startAttempt(InstallationId $installation, int $queueId, int $attemptNo, string $workerId, \DateTimeImmutable $now): int;

    public function finishAttempt(InstallationId $installation, int $attemptId, string $outcome, ?string $errorCode, ?int $httpStatus, \DateTimeImmutable $now): void;

    /**
     * Itens presos em 'sending' há mais de $olderThanSeconds (worker interrompido):
     * sem envio iniciado → voltam para 'scheduled'; com envio iniciado → 'failed' (resultado incerto, sem novo envio).
     *
     * @return array{requeued: int, unknown: int}
     */
    public function recoverStuck(InstallationId $installation, \DateTimeImmutable $now, int $olderThanSeconds): array;

    public function heartbeat(string $workerId, \DateTimeImmutable $startedAt, \DateTimeImmutable $now, string $summary): void;
}
