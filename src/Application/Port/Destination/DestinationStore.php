<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Destination;

use Sinergia\Application\Destination\AvailableDestination;
use Sinergia\Application\Destination\DestinationFacts;
use Sinergia\Application\Destination\DestinationRecord;
use Sinergia\Application\Destination\Eligibility;
use Sinergia\Domain\Installation\InstallationId;

/** Destinos de cada conta. TODA leitura/escrita é restrita ao installation_id recebido. */
interface DestinationStore
{
    /** @param list<AvailableDestination> $items substitui o resultado da sincronização anterior DA CONTA */
    public function replaceAvailable(InstallationId $installation, array $items, \DateTimeImmutable $now): void;

    /** @return list<AvailableDestination> com o indicador "já cadastrado" */
    public function available(InstallationId $installation): array;

    public function availableByPickKey(InstallationId $installation, string $pickKey): ?AvailableDestination;

    /** @return list<DestinationRecord> */
    public function all(InstallationId $installation): array;

    public function byPublicKey(InstallationId $installation, string $publicKey): ?DestinationRecord;

    /** Cadastra (ou devolve o já cadastrado com o mesmo provider_ref DA CONTA). */
    public function add(InstallationId $installation, string $type, string $providerRef, string $name, DestinationFacts $facts, int $userId, \DateTimeImmutable $now): DestinationRecord;

    public function updateFacts(InstallationId $installation, int $id, string $name, DestinationFacts $facts, \DateTimeImmutable $now): void;

    /** @param list<int> $subnicheIds */
    public function updateSettings(InstallationId $installation, int $id, int $nicheId, array $subnicheIds, string $mode, string $windowStart, string $windowEnd, int $intervalMinutes, \DateTimeImmutable $now): void;

    public function setPaused(InstallationId $installation, int $id, bool $paused, \DateTimeImmutable $now): void;

    public function setDeclaration(InstallationId $installation, int $id, string $kind, bool $declared, int $userId, string $version, \DateTimeImmutable $now): void;

    /** Grava rótulo, motivo, bloqueio técnico e status derivados. */
    public function saveEvaluation(InstallationId $installation, int $id, Eligibility $eligibility, string $status, \DateTimeImmutable $now): void;

    public function remove(InstallationId $installation, int $id): void;

    public function markTestSent(InstallationId $installation, int $id, \DateTimeImmutable $now): void;

    /**
     * Nichos da conta com subnichos ATIVADOS (tela Nichos), para associar ao destino.
     *
     * @return list<array{id: int, slug: string, name: string, subniches: list<array{id: int, slug: string, name: string}>}>
     */
    public function nicheOptions(InstallationId $installation): array;
}
