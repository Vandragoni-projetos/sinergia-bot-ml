<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Niche\NicheFilters;
use Sinergia\Application\Port\Queue\QueueDecisions;
use Sinergia\Application\Port\Queue\QueueStore;
use Sinergia\Application\Queue\ClaimedItem;
use Sinergia\Application\Queue\PlannerCandidate;
use Sinergia\Application\Queue\PlannerDestination;
use Sinergia\Domain\Installation\InstallationId;

/** Fila por conta. TODA consulta filtra por installation_id (exceto activeInstallations e heartbeat). */
final class DispatchQueueRepository implements QueueStore, QueueDecisions
{
    private const string OPEN = "('scheduled', 'pending_approval', 'awaiting_affiliate_link', 'sending')";

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function activeInstallations(): array
    {
        $rows = $this->pdo->query("SELECT id FROM installations WHERE bot_status = 'active' AND status = 'active' ORDER BY id");

        return $rows === false ? [] : array_values(array_map(static fn (mixed $id): InstallationId => new InstallationId((int) $id), $rows->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function withInstallationLock(InstallationId $installation, callable $work): mixed
    {
        $name = 'sbm:worker:' . $installation->value;
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $stmt->execute(['name' => $name]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return null;
        }
        try {
            return $work();
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $name]);
        }
    }

    public function botActive(InstallationId $installation): bool
    {
        return $this->scalar('SELECT bot_status FROM installations WHERE id = :inst', $installation) === 'active';
    }

    public function setBotStatus(InstallationId $installation, bool $active, int $userId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE installations SET bot_status = :status, bot_status_changed_at = :at, bot_status_changed_by = :user WHERE id = :inst')
            ->execute(['status' => $active ? 'active' : 'paused', 'at' => self::ts($now), 'user' => $userId, 'inst' => $installation->value]);
    }

    public function timezone(InstallationId $installation): string
    {
        return (string) ($this->scalar('SELECT timezone FROM installations WHERE id = :inst', $installation) ?? 'America/Sao_Paulo');
    }

    public function revalidationDue(InstallationId $installation, \DateTimeImmutable $now, int $everySeconds): bool
    {
        if ((int) $this->scalar('SELECT COUNT(*) FROM destinations WHERE installation_id = :inst', $installation) === 0) {
            return false;
        }
        $last = $this->scalar('SELECT destinations_revalidated_at FROM installations WHERE id = :inst', $installation);

        return $last === null || $now->getTimestamp() - self::date((string) $last)->getTimestamp() >= $everySeconds;
    }

    public function markRevalidated(InstallationId $installation, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE installations SET destinations_revalidated_at = :at WHERE id = :inst')->execute(['at' => self::ts($now), 'inst' => $installation->value]);
    }

    public function lastSelectionAt(InstallationId $installation): ?\DateTimeImmutable
    {
        $at = $this->scalar('SELECT MAX(started_at) FROM offer_selection_runs WHERE installation_id = :inst', $installation);

        return $at === null ? null : self::date((string) $at);
    }

    public function plannerDestinations(InstallationId $installation): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.mode, TIME_FORMAT(d.window_start, \'%H:%i\') AS ws, TIME_FORMAT(d.window_end, \'%H:%i\') AS we, d.interval_minutes,
                    d.niche_id, d.last_sent_at,
                    (SELECT MAX(q.scheduled_for) FROM dispatch_queue q WHERE q.installation_id = d.installation_id AND q.destination_id = d.id AND q.status IN ' . self::OPEN . ') AS last_planned,
                    (SELECT q.subniche_id FROM dispatch_queue q WHERE q.installation_id = d.installation_id AND q.destination_id = d.id ORDER BY q.id DESC LIMIT 1) AS last_sub
             FROM destinations d
             WHERE d.installation_id = :inst AND d.status = \'active\' AND d.niche_id IS NOT NULL
             ORDER BY d.id'
        );
        $stmt->execute(['inst' => $installation->value]);
        $subs = $this->pdo->prepare('SELECT destination_id, subniche_id FROM destination_subniches WHERE installation_id = :inst');
        $subs->execute(['inst' => $installation->value]);
        $byDestination = [];
        foreach ($subs->fetchAll() as $row) {
            $byDestination[(int) $row['destination_id']][] = (int) $row['subniche_id'];
        }

        return array_values(array_map(static fn (array $r): PlannerDestination => new PlannerDestination(
            (int) $r['id'], (string) $r['mode'], (string) $r['ws'], (string) $r['we'], (int) $r['interval_minutes'], (int) $r['niche_id'],
            $byDestination[(int) $r['id']] ?? [],
            $r['last_sent_at'] === null ? null : self::date((string) $r['last_sent_at']),
            $r['last_planned'] === null ? null : self::date((string) $r['last_planned']),
            $r['last_sub'] === null ? null : (int) $r['last_sub'],
        ), $stmt->fetchAll()));
    }

    public function plannerCandidates(InstallationId $installation): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ml_product_id, niche_id, subniche_id, sort_order, price, original_price, discount_pct
             FROM account_offer_candidates
             WHERE installation_id = :inst
               AND run_id = (SELECT MAX(id) FROM offer_selection_runs WHERE installation_id = :inst2 AND status IN (\'completed\', \'partial\'))
             ORDER BY sort_order'
        );
        $stmt->execute(['inst' => $installation->value, 'inst2' => $installation->value]);

        return array_values(array_map(static fn (array $r): PlannerCandidate => new PlannerCandidate(
            (string) $r['ml_product_id'], (int) $r['niche_id'], (int) $r['subniche_id'], (int) $r['sort_order'],
            PublicCatalogCacheRepository::cents((string) $r['price']),
            $r['original_price'] === null ? null : PublicCatalogCacheRepository::cents((string) $r['original_price']),
            (int) $r['discount_pct'],
        ), $stmt->fetchAll()));
    }

    public function usedProducts(InstallationId $installation, int $destinationId): array
    {
        $stmt = $this->pdo->prepare('SELECT ml_product_id FROM dispatch_queue WHERE installation_id = :inst AND destination_id = :dest');
        $stmt->execute(['inst' => $installation->value, 'dest' => $destinationId]);

        return array_fill_keys(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), true);
    }

    public function activeLinkId(InstallationId $installation, string $productId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM affiliate_links WHERE installation_id = :inst AND active_product_id = :product');
        $stmt->execute(['inst' => $installation->value, 'product' => $productId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function insertItem(InstallationId $installation, int $destinationId, PlannerCandidate $candidate, string $status, ?int $linkId, \DateTimeImmutable $scheduledFor, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO dispatch_queue
                (installation_id, public_key, destination_id, niche_id, subniche_id, ml_product_id, status, affiliate_link_id, scheduled_for,
                 planned_price, planned_original, planned_discount, created_at, updated_at)
             VALUES (:inst, :key, :dest, :niche, :sub, :product, :status, :link, :at, :price, :original, :discount, :now, :now2)'
        );
        $stmt->execute([
            'inst' => $installation->value, 'key' => bin2hex(random_bytes(10)), 'dest' => $destinationId, 'niche' => $candidate->nicheId,
            'sub' => $candidate->subnicheId, 'product' => $candidate->productId, 'status' => $status, 'link' => $linkId,
            'at' => self::ts($scheduledFor), 'price' => PublicCatalogCacheRepository::decimal($candidate->priceCents),
            'original' => PublicCatalogCacheRepository::decimal($candidate->originalCents), 'discount' => $candidate->discountPct,
            'now' => self::ts($now), 'now2' => self::ts($now),
        ]);

        return $stmt->rowCount() === 1;
    }

    public function promoteAwaiting(InstallationId $installation, \DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dispatch_queue q
             JOIN affiliate_links l ON l.installation_id = q.installation_id AND l.active_product_id = q.ml_product_id
             JOIN destinations d ON d.installation_id = q.installation_id AND d.id = q.destination_id
             SET q.affiliate_link_id = l.id, q.status = IF(d.mode = \'auto\', \'scheduled\', \'pending_approval\'), q.last_error = NULL, q.updated_at = :now
             WHERE q.installation_id = :inst AND q.status = \'awaiting_affiliate_link\''
        );
        $stmt->execute(['now' => self::ts($now), 'inst' => $installation->value]);

        return $stmt->rowCount();
    }

    public function dueItemIds(InstallationId $installation, \DateTimeImmutable $now, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.id FROM dispatch_queue q
             JOIN destinations d ON d.installation_id = q.installation_id AND d.id = q.destination_id AND d.status = \'active\'
             WHERE q.installation_id = :inst AND q.status = \'scheduled\' AND q.scheduled_for <= :now
               AND (q.next_attempt_at IS NULL OR q.next_attempt_at <= :now2)
             ORDER BY q.scheduled_for, q.id
             LIMIT ' . max(1, min($limit, 50))
        );
        $stmt->execute(['inst' => $installation->value, 'now' => self::ts($now), 'now2' => self::ts($now)]);

        return array_values(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function claim(InstallationId $installation, int $id, string $token, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dispatch_queue SET status = \'sending\', claim_token = :token, claimed_at = :now, send_started_at = NULL, updated_at = :now2
             WHERE installation_id = :inst AND id = :id AND status = \'scheduled\''
        );
        $stmt->execute(['token' => $token, 'now' => self::ts($now), 'now2' => self::ts($now), 'inst' => $installation->value, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    public function loadClaimed(InstallationId $installation, int $id, string $token): ?ClaimedItem
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.id, q.claim_token, q.attempts, q.ml_product_id, q.niche_id, q.affiliate_link_id, q.decided_by_user_id,
                    i.bot_status, i.timezone,
                    d.id AS dest_id, d.status AS dest_status, d.mode, d.provider_ref,
                    TIME_FORMAT(d.window_start, \'%H:%i\') AS ws, TIME_FORMAT(d.window_end, \'%H:%i\') AS we, d.interval_minutes, d.last_sent_at,
                    l.id AS link_id, l.affiliate_url,
                    p.name AS product_name, p.picture_url,
                    an.min_discount_pct, an.min_price, an.max_price, an.require_photo
             FROM dispatch_queue q
             JOIN installations i ON i.id = q.installation_id
             LEFT JOIN destinations d ON d.installation_id = q.installation_id AND d.id = q.destination_id
             LEFT JOIN affiliate_links l ON l.installation_id = q.installation_id AND l.active_product_id = q.ml_product_id
             LEFT JOIN ml_products p ON p.ml_product_id = q.ml_product_id
             LEFT JOIN account_niches an ON an.installation_id = q.installation_id AND an.niche_id = q.niche_id
             WHERE q.installation_id = :inst AND q.id = :id AND q.claim_token = :token AND q.status = \'sending\''
        );
        $stmt->execute(['inst' => $installation->value, 'id' => $id, 'token' => $token]);
        $r = $stmt->fetch();
        if (!is_array($r)) {
            return null;
        }
        $filters = $r['require_photo'] === null ? NicheFilters::defaults() : new NicheFilters(
            $r['min_discount_pct'] === null ? null : (int) $r['min_discount_pct'],
            $r['min_price'] === null ? null : PublicCatalogCacheRepository::cents((string) $r['min_price']),
            $r['max_price'] === null ? null : PublicCatalogCacheRepository::cents((string) $r['max_price']),
            (bool) $r['require_photo'],
        );

        return new ClaimedItem(
            (int) $r['id'], (string) $r['claim_token'], (int) $r['attempts'], (string) $r['ml_product_id'], (int) $r['niche_id'],
            $r['affiliate_link_id'] === null ? null : (int) $r['affiliate_link_id'],
            $r['decided_by_user_id'] === null ? null : (string) $r['decided_by_user_id'],
            $r['bot_status'] === 'active', $r['dest_id'] === null ? null : (int) $r['dest_id'],
            $r['dest_status'] === null ? null : (string) $r['dest_status'], $r['mode'] === null ? null : (string) $r['mode'],
            $r['provider_ref'] === null ? null : (string) $r['provider_ref'],
            $r['ws'] === null ? null : (string) $r['ws'], $r['we'] === null ? null : (string) $r['we'],
            $r['interval_minutes'] === null ? null : (int) $r['interval_minutes'],
            $r['last_sent_at'] === null ? null : self::date((string) $r['last_sent_at']),
            (string) $r['timezone'],
            $r['link_id'] === null ? null : (int) $r['link_id'], $r['affiliate_url'] === null ? null : (string) $r['affiliate_url'],
            $r['product_name'] === null ? null : (string) $r['product_name'], $r['picture_url'] === null ? null : (string) $r['picture_url'],
            $filters,
        );
    }

    public function release(InstallationId $installation, int $id, string $token, \DateTimeImmutable $scheduledFor, ?\DateTimeImmutable $nextAttemptAt, ?string $error, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE dispatch_queue SET status = \'scheduled\', claim_token = NULL, claimed_at = NULL, send_started_at = NULL,
                scheduled_for = :at, next_attempt_at = :next, last_error = :error, last_error_at = :now, updated_at = :now2
             WHERE installation_id = :inst AND id = :id AND claim_token = :token'
        )->execute([
            'at' => self::ts($scheduledFor), 'next' => $nextAttemptAt === null ? null : self::ts($nextAttemptAt), 'error' => $error,
            'now' => self::ts($now), 'now2' => self::ts($now), 'inst' => $installation->value, 'id' => $id, 'token' => $token,
        ]);
    }

    public function finish(InstallationId $installation, int $id, string $token, string $status, ?string $error, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE dispatch_queue SET status = :status, claim_token = NULL, claimed_at = NULL, last_error = :error, last_error_at = :now,
                affiliate_link_id = IF(:status2 = \'awaiting_affiliate_link\', NULL, affiliate_link_id), updated_at = :now2
             WHERE installation_id = :inst AND id = :id AND claim_token = :token'
        )->execute([
            'status' => $status, 'status2' => $status, 'error' => $error, 'now' => self::ts($now), 'now2' => self::ts($now),
            'inst' => $installation->value, 'id' => $id, 'token' => $token,
        ]);
    }

    public function markSendStarted(InstallationId $installation, int $id, string $token, int $linkId, array $message, int $priceCents, ?int $originalCents, int $discount, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dispatch_queue SET send_started_at = :now, attempts = attempts + 1, affiliate_link_id = :link, message_json = :message,
                planned_price = :price, planned_original = :original, planned_discount = :discount, updated_at = :now2
             WHERE installation_id = :inst AND id = :id AND claim_token = :token AND status = \'sending\''
        );
        $stmt->execute([
            'now' => self::ts($now), 'link' => $linkId, 'message' => json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'price' => PublicCatalogCacheRepository::decimal($priceCents), 'original' => PublicCatalogCacheRepository::decimal($originalCents),
            'discount' => $discount, 'now2' => self::ts($now), 'inst' => $installation->value, 'id' => $id, 'token' => $token,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markSent(InstallationId $installation, int $id, string $token, ?string $providerMessageId, \DateTimeImmutable $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE dispatch_queue SET status = \'sent\', sent_at = :now, provider_message_id = :msg, claim_token = NULL, last_error = NULL, updated_at = :now2
                 WHERE installation_id = :inst AND id = :id AND claim_token = :token'
            )->execute(['now' => self::ts($now), 'msg' => $providerMessageId, 'now2' => self::ts($now), 'inst' => $installation->value, 'id' => $id, 'token' => $token]);
            $this->pdo->prepare(
                'UPDATE destinations d JOIN dispatch_queue q ON q.installation_id = d.installation_id AND q.destination_id = d.id
                 SET d.last_sent_at = :now WHERE q.installation_id = :inst AND q.id = :id'
            )->execute(['now' => self::ts($now), 'inst' => $installation->value, 'id' => $id]);
            $this->pdo->prepare(
                'UPDATE affiliate_links l JOIN dispatch_queue q ON q.installation_id = l.installation_id AND q.affiliate_link_id = l.id
                 SET l.use_count = l.use_count + 1, l.first_used_at = COALESCE(l.first_used_at, :now), l.last_used_at = :now2
                 WHERE q.installation_id = :inst AND q.id = :id'
            )->execute(['now' => self::ts($now), 'now2' => self::ts($now), 'inst' => $installation->value, 'id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function startAttempt(InstallationId $installation, int $queueId, int $attemptNo, string $workerId, \DateTimeImmutable $now): int
    {
        $this->pdo->prepare('INSERT INTO dispatch_attempts (installation_id, queue_id, attempt_no, worker_id, started_at) VALUES (:inst, :queue, :no, :worker, :at)')
            ->execute(['inst' => $installation->value, 'queue' => $queueId, 'no' => $attemptNo, 'worker' => mb_substr($workerId, 0, 64), 'at' => self::ts($now)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function finishAttempt(InstallationId $installation, int $attemptId, string $outcome, ?string $errorCode, ?int $httpStatus, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE dispatch_attempts SET finished_at = :at, outcome = :outcome, error_code = :code, http_status = :http WHERE installation_id = :inst AND id = :id')
            ->execute(['at' => self::ts($now), 'outcome' => $outcome, 'code' => $errorCode, 'http' => $httpStatus, 'inst' => $installation->value, 'id' => $attemptId]);
    }

    public function recoverStuck(InstallationId $installation, \DateTimeImmutable $now, int $olderThanSeconds): array
    {
        $cut = self::ts($now->modify('-' . $olderThanSeconds . ' seconds'));
        $scope = ['inst' => $installation->value, 'cut' => $cut, 'now' => self::ts($now)];
        // Envio iniciado e sem resultado: pode ter saído → falha definitiva, nunca reenviado.
        $unknown = $this->pdo->prepare(
            'UPDATE dispatch_queue SET status = \'failed\', last_error = \'interrupted_unknown_outcome\', last_error_at = :now, claim_token = NULL, updated_at = :now2
             WHERE installation_id = :inst AND status = \'sending\' AND claimed_at < :cut AND send_started_at IS NOT NULL'
        );
        $unknown->execute($scope + ['now2' => self::ts($now)]);
        $this->pdo->prepare(
            'UPDATE dispatch_attempts a JOIN dispatch_queue q ON q.installation_id = a.installation_id AND q.id = a.queue_id
             SET a.finished_at = :now, a.outcome = \'unknown\', a.error_code = \'interrupted\'
             WHERE a.installation_id = :inst AND a.finished_at IS NULL AND q.status = \'failed\' AND q.last_error = \'interrupted_unknown_outcome\''
        )->execute(['now' => self::ts($now), 'inst' => $installation->value]);
        // Reservado, mas o envio nem começou: volta para a fila com segurança.
        $requeued = $this->pdo->prepare(
            'UPDATE dispatch_queue SET status = \'scheduled\', claim_token = NULL, claimed_at = NULL, updated_at = :now
             WHERE installation_id = :inst AND status = \'sending\' AND claimed_at < :cut AND send_started_at IS NULL'
        );
        $requeued->execute($scope);

        return ['requeued' => $requeued->rowCount(), 'unknown' => $unknown->rowCount()];
    }

    public function heartbeat(string $workerId, \DateTimeImmutable $startedAt, \DateTimeImmutable $now, string $summary): void
    {
        $this->pdo->prepare('REPLACE INTO worker_heartbeats (worker_id, started_at, last_beat_at, last_summary) VALUES (:w, :s, :n, :sum)')
            ->execute(['w' => mb_substr($workerId, 0, 64), 's' => self::ts($startedAt), 'n' => self::ts($now), 'sum' => mb_substr($summary, 0, 255)]);
    }

    public function approve(InstallationId $installation, string $key, int $userId, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dispatch_queue SET status = \'scheduled\', decided_by_user_id = :user, decided_at = :now, updated_at = :now2
             WHERE installation_id = :inst AND public_key = :key AND status = \'pending_approval\' AND affiliate_link_id IS NOT NULL'
        );
        $stmt->execute(['user' => $userId, 'now' => self::ts($now), 'now2' => self::ts($now), 'inst' => $installation->value, 'key' => $key]);

        return $stmt->rowCount() === 1;
    }

    public function skip(InstallationId $installation, string $key, int $userId, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dispatch_queue SET status = \'skipped\', decided_by_user_id = :user, decided_at = :now, updated_at = :now2
             WHERE installation_id = :inst AND public_key = :key AND status IN (\'pending_approval\', \'scheduled\', \'awaiting_affiliate_link\')'
        );
        $stmt->execute(['user' => $userId, 'now' => self::ts($now), 'now2' => self::ts($now), 'inst' => $installation->value, 'key' => $key]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Visão da tela Fila (sem ids internos, JIDs ou links).
     *
     * @return array{bot_active: bool, last_beat: ?\DateTimeImmutable, upcoming: list<array<string, mixed>>, pending: list<array<string, mixed>>, awaiting: list<array<string, mixed>>, history: list<array<string, mixed>>}
     */
    public function overview(InstallationId $installation): array
    {
        $list = function (string $where, string $order, int $limit) use ($installation): array {
            $stmt = $this->pdo->prepare(
                'SELECT q.public_key, q.status, q.scheduled_for, q.sent_at, q.last_error, q.planned_price, q.planned_original, q.planned_discount,
                        d.name AS destination, p.name AS product
                 FROM dispatch_queue q
                 JOIN destinations d ON d.installation_id = q.installation_id AND d.id = q.destination_id
                 LEFT JOIN ml_products p ON p.ml_product_id = q.ml_product_id
                 WHERE q.installation_id = :inst AND ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit
            );
            $stmt->execute(['inst' => $installation->value]);

            return array_values(array_map(static fn (array $r): array => [
                'key' => (string) $r['public_key'],
                'status' => (string) $r['status'],
                'when' => self::date((string) ($r['sent_at'] ?? $r['scheduled_for'])),
                'destination' => (string) $r['destination'],
                'product' => (string) ($r['product'] ?? ''),
                'price' => PublicCatalogCacheRepository::cents((string) $r['planned_price']),
                'original' => $r['planned_original'] === null ? null : PublicCatalogCacheRepository::cents((string) $r['planned_original']),
                'discount' => (int) $r['planned_discount'],
                'error' => $r['last_error'] === null ? null : (string) $r['last_error'],
            ], $stmt->fetchAll()));
        };
        $beat = $this->pdo->query('SELECT MAX(last_beat_at) FROM worker_heartbeats');
        $lastBeat = $beat === false ? false : $beat->fetchColumn();

        return [
            'bot_active' => $this->botActive($installation),
            'last_beat' => is_string($lastBeat) ? self::date($lastBeat) : null,
            'upcoming' => $list("q.status IN ('scheduled', 'sending')", 'q.scheduled_for, q.id', 30),
            'pending' => $list("q.status = 'pending_approval'", 'q.scheduled_for, q.id', 50),
            'awaiting' => $list("q.status = 'awaiting_affiliate_link'", 'q.scheduled_for, q.id', 50),
            'history' => $list("q.status IN ('sent', 'failed', 'skipped', 'cancelled')", 'COALESCE(q.sent_at, q.updated_at) DESC, q.id DESC', 30),
        ];
    }

    private function scalar(string $sql, InstallationId $installation): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['inst' => $installation->value]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    private static function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private static function ts(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
