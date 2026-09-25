<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Port\Queue\QueueDecisions;
use Sinergia\Application\Port\Queue\QueueStore;
use Sinergia\Shared\Clock\Clock;

/** Ações do cliente na Fila, sempre na conta do TenantContext: Aprovar, Pular, Pausar/Ativar bot. */
final class ManageQueue
{
    public function __construct(
        private readonly QueueStore $store,
        private readonly QueueDecisions $decisions,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws QueueRejected */
    public function approve(TenantContext $tenant, string $key): void
    {
        $this->decide($tenant, $key, 'approve');
    }

    /** @throws QueueRejected */
    public function skip(TenantContext $tenant, string $key): void
    {
        $this->decide($tenant, $key, 'skip');
    }

    public function setBot(TenantContext $tenant, bool $active): void
    {
        $this->store->setBotStatus($tenant->installationId, $active, $tenant->userId, $this->clock->now());
        $this->logger->info('queue.bot_' . ($active ? 'activated' : 'paused'), ['installation_id' => $tenant->installationId->value, 'user_id' => $tenant->userId]);
    }

    /** @throws QueueRejected */
    private function decide(TenantContext $tenant, string $key, string $decision): void
    {
        if (preg_match('/^[a-f0-9]{20}$/', $key) !== 1) {
            throw new QueueRejected(QueueRejected::NOT_FOUND);
        }
        $ok = $decision === 'approve'
            ? $this->decisions->approve($tenant->installationId, $key, $tenant->userId, $this->clock->now())
            : $this->decisions->skip($tenant->installationId, $key, $tenant->userId, $this->clock->now());
        if (!$ok) {
            throw new QueueRejected(QueueRejected::NOT_FOUND);
        }
        $this->logger->info('queue.' . $decision, ['installation_id' => $tenant->installationId->value, 'user_id' => $tenant->userId]);
    }
}
