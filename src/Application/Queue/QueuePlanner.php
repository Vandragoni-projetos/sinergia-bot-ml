<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Port\Queue\QueueStore;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Clock\Clock;

/**
 * Planejamento (plano F1 v2.1, seção 5, passo 2), por conta e por destino ATIVO:
 *  - horizonte de 24 h a partir de agora;
 *  - horários: começa em max(agora, último planejado + intervalo, último enviado + intervalo) e avança de
 *    "intervalo" em "intervalo", sempre ajustado para dentro da janela diária (fuso da conta);
 *  - produto: rodízio entre os subnichos DO DESTINO (retoma depois do último usado); dentro do subnicho, o candidato
 *    de melhor posição da última seleção DA CONTA que ainda não foi usado NESTE destino (qualquer status);
 *  - status: com link ativo da conta → 'scheduled' (auto) ou 'pending_approval' (manual); sem link →
 *    'awaiting_affiliate_link' (nunca troca por URL comum).
 */
final class QueuePlanner
{
    public const int HORIZON_HOURS = 24;
    public const int MAX_NEW_PER_DESTINATION = 100;

    public function __construct(
        private readonly QueueStore $store,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return int itens criados */
    public function plan(InstallationId $installation): int
    {
        $now = $this->clock->now();
        $horizon = $now->modify('+' . self::HORIZON_HOURS . ' hours');
        $timezone = new \DateTimeZone($this->store->timezone($installation));
        $candidates = $this->store->plannerCandidates($installation);
        $created = 0;

        foreach ($this->store->plannerDestinations($installation) as $destination) {
            if ($destination->subnicheIds === []) {
                continue;
            }
            $window = new SendWindow($destination->windowStart, $destination->windowEnd, $timezone);
            $interval = '+' . $destination->intervalMinutes . ' minutes';
            $next = $now;
            foreach ([$destination->lastPlannedAt, $destination->lastSentAt] as $previous) {
                if ($previous !== null && $previous->modify($interval) > $next) {
                    $next = $previous->modify($interval);
                }
            }
            $next = $window->nextAllowed($next);

            $used = $this->store->usedProducts($installation, $destination->id);
            $rotation = self::rotation($destination->subnicheIds, $destination->lastSubnicheId);
            $turn = 0;
            $added = 0;
            while ($next < $horizon && $added < self::MAX_NEW_PER_DESTINATION) {
                $picked = null;
                // Tenta cada subnicho no máximo uma vez por horário, começando pelo da vez.
                for ($i = 0; $i < count($rotation) && $picked === null; $i++) {
                    $subniche = $rotation[($turn + $i) % count($rotation)];
                    foreach ($candidates as $candidate) {
                        if ($candidate->subnicheId === $subniche && $candidate->nicheId === $destination->nicheId && !isset($used[$candidate->productId])) {
                            $picked = $candidate;
                            $turn = ($turn + $i + 1) % count($rotation);
                            break;
                        }
                    }
                }
                if ($picked === null) {
                    break;   // acabaram os candidatos deste destino
                }
                $linkId = $this->store->activeLinkId($installation, $picked->productId);
                $status = $linkId === null ? 'awaiting_affiliate_link' : ($destination->mode === 'auto' ? 'scheduled' : 'pending_approval');
                if ($this->store->insertItem($installation, $destination->id, $picked, $status, $linkId, $next, $now)) {
                    $created++;
                    $added++;
                }
                $used[$picked->productId] = true;
                $next = $window->nextAllowed($next->modify($interval));
            }
        }
        if ($created > 0) {
            $this->logger->info('queue.planned', ['installation_id' => $installation->value, 'created' => $created]);
        }

        return $created;
    }

    /**
     * @param list<int> $subnicheIds
     *
     * @return list<int> subnichos a partir do seguinte ao último usado
     */
    private static function rotation(array $subnicheIds, ?int $last): array
    {
        sort($subnicheIds);
        $position = $last === null ? false : array_search($last, $subnicheIds, true);
        if ($position === false) {
            return $subnicheIds;
        }

        return array_merge(array_slice($subnicheIds, $position + 1), array_slice($subnicheIds, 0, $position + 1));
    }
}
