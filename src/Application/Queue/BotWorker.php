<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Destination\DestinationRejected;
use Sinergia\Application\Destination\ManageDestinations;
use Sinergia\Application\Offer\SelectOffers;
use Sinergia\Application\Port\Queue\QueueStore;
use Sinergia\Application\Port\WhatsApp\WhatsAppProviderFailure;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Clock\Clock;

/**
 * Um ciclo do bot:worker (plano F1 v2.1, seção 5). Para cada conta com bot ATIVO, sob GET_LOCK da conta:
 *  0. recupera itens presos em 'sending' (worker interrompido);
 *  1. revalida os destinos a cada ~6 h (sinais técnicos do WhatsApp da conta);
 *  2. renova a seleção de ofertas da conta a cada ~6 h (etapa 4, token ML da conta);
 *  3. planeja 24 h e promove itens que ganharam link;
 *  4. envia os vencidos.
 * Falha numa conta não interrompe as outras.
 */
final class BotWorker
{
    public const int REVALIDATE_EVERY_SECONDS = 6 * 3600;
    public const int SELECT_EVERY_SECONDS = 6 * 3600;
    public const int STUCK_AFTER_SECONDS = 600;
    public const int SENDS_PER_CYCLE = 5;

    public function __construct(
        private readonly QueueStore $store,
        private readonly QueuePlanner $planner,
        private readonly QueueSender $sender,
        private readonly ManageDestinations $destinations,
        private readonly SelectOffers $selector,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<int, array<string, mixed>|string> resumo por conta ('busy' quando outra instância já a processa) */
    public function runOnce(string $workerId, \DateTimeImmutable $startedAt): array
    {
        $summary = [];
        foreach ($this->store->activeInstallations() as $installation) {
            try {
                $result = $this->store->withInstallationLock($installation, fn (): array => $this->cycle($installation, $workerId));
                $summary[$installation->value] = $result ?? 'busy';
            } catch (\Throwable $e) {
                $summary[$installation->value] = 'error';
                $this->logger->error('worker.installation_failed', ['installation_id' => $installation->value, 'exception' => $e::class]);
            }
        }
        $this->store->heartbeat($workerId, $startedAt, $this->clock->now(), substr((string) json_encode($summary), 0, 255));

        return $summary;
    }

    /** @return array<string, mixed> */
    private function cycle(InstallationId $installation, string $workerId): array
    {
        $result = ['recovered' => $this->store->recoverStuck($installation, $this->clock->now(), self::STUCK_AFTER_SECONDS)];

        if ($this->store->revalidationDue($installation, $this->clock->now(), self::REVALIDATE_EVERY_SECONDS)) {
            try {
                $this->destinations->revalidate($installation);
                $result['revalidated'] = true;
            } catch (DestinationRejected|WhatsAppProviderFailure $e) {
                $result['revalidated'] = $e instanceof DestinationRejected ? $e->reason : $e->errorCode;
            }
            $this->store->markRevalidated($installation, $this->clock->now());
        }

        $last = $this->store->lastSelectionAt($installation);
        if ($last === null || $this->clock->now()->getTimestamp() - $last->getTimestamp() >= self::SELECT_EVERY_SECONDS) {
            $result['selection'] = $this->selector->run($installation)->status;
        }

        $result['planned'] = $this->planner->plan($installation);
        $result['promoted'] = $this->store->promoteAwaiting($installation, $this->clock->now());
        $result['sent'] = $this->sender->sendDue($installation, $workerId, self::SENDS_PER_CYCLE);

        return $result;
    }
}
