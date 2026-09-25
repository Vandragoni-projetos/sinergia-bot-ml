<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Destination\AvailableDestination;
use Sinergia\Application\Destination\DestinationDeclarations;
use Sinergia\Application\Destination\DestinationFacts;
use Sinergia\Application\Destination\DestinationRecord;
use Sinergia\Application\Destination\Eligibility;
use Sinergia\Application\Port\Destination\DestinationStore;
use Sinergia\Domain\Installation\InstallationId;

/** Destinos por conta. TODA consulta e escrita filtra por installation_id. */
final class DestinationRepository implements DestinationStore
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function replaceAvailable(InstallationId $installation, array $items, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($installation, $items, $now): void {
            $this->pdo->prepare('DELETE FROM whatsapp_available_destinations WHERE installation_id = :inst')->execute(['inst' => $installation->value]);
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO whatsapp_available_destinations
                    (installation_id, provider_ref, pick_key, type, name, tech_we_are_admin, tech_join_approval, tech_announce_only, tech_is_community, participants, fetched_at)
                 VALUES (:inst, :ref, :pick, :type, :name, :admin, :approval, :announce, :community, :participants, :at)'
            );
            foreach ($items as $item) {
                $insert->execute([
                    'inst' => $installation->value, 'ref' => $item->providerRef, 'pick' => $item->pickKey, 'type' => $item->type,
                    'name' => mb_substr($item->name !== '' ? $item->name : 'Sem nome', 0, 120),
                    'admin' => self::flag($item->weAreAdmin), 'approval' => self::flag($item->joinApproval),
                    'announce' => self::flag($item->announceOnly), 'community' => self::flag($item->isCommunity),
                    'participants' => $item->participants, 'at' => self::ts($now),
                ]);
            }
        });
    }

    public function available(InstallationId $installation): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, (d.id IS NOT NULL) AS registered
             FROM whatsapp_available_destinations a
             LEFT JOIN destinations d ON d.installation_id = a.installation_id AND d.provider_ref = a.provider_ref
             WHERE a.installation_id = :inst
             ORDER BY a.type, a.name'
        );
        $stmt->execute(['inst' => $installation->value]);

        return array_values(array_map(self::availableFromRow(...), $stmt->fetchAll()));
    }

    public function availableByPickKey(InstallationId $installation, string $pickKey): ?AvailableDestination
    {
        $stmt = $this->pdo->prepare('SELECT a.*, 0 AS registered FROM whatsapp_available_destinations a WHERE a.installation_id = :inst AND a.pick_key = :pick');
        $stmt->execute(['inst' => $installation->value, 'pick' => $pickKey]);
        $row = $stmt->fetch();

        return is_array($row) ? self::availableFromRow($row) : null;
    }

    public function all(InstallationId $installation): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, TIME_FORMAT(d.window_start, \'%H:%i\') AS ws, TIME_FORMAT(d.window_end, \'%H:%i\') AS we,
                    pu.name AS public_by_name, mu.name AS media_by_name
             FROM destinations d
             LEFT JOIN users pu ON pu.installation_id = d.installation_id AND pu.id = d.public_declared_by_user_id
             LEFT JOIN users mu ON mu.installation_id = d.installation_id AND mu.id = d.media_declared_by_user_id
             WHERE d.installation_id = :inst
             ORDER BY d.type, d.name, d.id'
        );
        $stmt->execute(['inst' => $installation->value]);
        $rows = $stmt->fetchAll();

        $subs = $this->pdo->prepare('SELECT destination_id, subniche_id FROM destination_subniches WHERE installation_id = :inst ORDER BY subniche_id');
        $subs->execute(['inst' => $installation->value]);
        $byDestination = [];
        foreach ($subs->fetchAll() as $sub) {
            $byDestination[(int) $sub['destination_id']][] = (int) $sub['subniche_id'];
        }

        return array_values(array_map(static fn (array $row): DestinationRecord => self::recordFromRow($row, $byDestination[(int) $row['id']] ?? []), $rows));
    }

    public function byPublicKey(InstallationId $installation, string $publicKey): ?DestinationRecord
    {
        foreach ($this->all($installation) as $record) {
            if (hash_equals($record->publicKey, $publicKey)) {
                return $record;
            }
        }

        return null;
    }

    public function add(InstallationId $installation, string $type, string $providerRef, string $name, DestinationFacts $facts, int $userId, \DateTimeImmutable $now): DestinationRecord
    {
        $this->pdo->prepare(
            'INSERT IGNORE INTO destinations
                (installation_id, public_key, type, provider_ref, name, tech_checked_at, tech_present, tech_is_channel, tech_we_are_admin,
                 tech_has_invite_link, tech_join_approval, tech_announce_only, tech_is_community, created_at, created_by_user_id, updated_at)
             VALUES (:inst, :key, :type, :ref, :name, :at, :present, :channel, :admin, :invite, :approval, :announce, :community, :at2, :user, :at3)'
        )->execute([
            'inst' => $installation->value, 'key' => bin2hex(random_bytes(10)), 'type' => $type, 'ref' => $providerRef,
            'name' => mb_substr($name, 0, 120), 'at' => self::ts($now), 'present' => (int) $facts->presentInWhatsApp,
            'channel' => (int) $facts->isChannel, 'admin' => self::flag($facts->weAreAdmin), 'invite' => self::flag($facts->hasInviteLink),
            'approval' => self::flag($facts->joinApproval), 'announce' => self::flag($facts->announceOnly),
            'community' => self::flag($facts->isCommunity), 'at2' => self::ts($now), 'user' => $userId, 'at3' => self::ts($now),
        ]);
        foreach ($this->all($installation) as $record) {
            if ($record->providerRef === $providerRef) {
                return $record;
            }
        }
        throw new \RuntimeException('Destino não gravado.');
    }

    public function updateFacts(InstallationId $installation, int $id, string $name, DestinationFacts $facts, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE destinations SET name = :name, tech_checked_at = :at, tech_present = :present, tech_we_are_admin = :admin,
                tech_has_invite_link = :invite, tech_join_approval = :approval, tech_announce_only = :announce, tech_is_community = :community,
                updated_at = :at2
             WHERE installation_id = :inst AND id = :id'
        )->execute([
            'name' => mb_substr($name, 0, 120), 'at' => self::ts($now), 'present' => (int) $facts->presentInWhatsApp,
            'admin' => self::flag($facts->weAreAdmin), 'invite' => self::flag($facts->hasInviteLink), 'approval' => self::flag($facts->joinApproval),
            'announce' => self::flag($facts->announceOnly), 'community' => self::flag($facts->isCommunity), 'at2' => self::ts($now),
            'inst' => $installation->value, 'id' => $id,
        ]);
    }

    public function updateSettings(InstallationId $installation, int $id, int $nicheId, array $subnicheIds, string $mode, string $windowStart, string $windowEnd, int $intervalMinutes, \DateTimeImmutable $now): void
    {
        $this->transaction(function () use ($installation, $id, $nicheId, $subnicheIds, $mode, $windowStart, $windowEnd, $intervalMinutes, $now): void {
            $update = $this->pdo->prepare(
                'UPDATE destinations SET niche_id = :niche, mode = :mode, window_start = :ws, window_end = :we, interval_minutes = :interval, updated_at = :at
                 WHERE installation_id = :inst AND id = :id'
            );
            $update->execute([
                'niche' => $nicheId, 'mode' => $mode, 'ws' => $windowStart . ':00', 'we' => $windowEnd . ':00', 'interval' => $intervalMinutes,
                'at' => self::ts($now), 'inst' => $installation->value, 'id' => $id,
            ]);
            $this->pdo->prepare('DELETE FROM destination_subniches WHERE installation_id = :inst AND destination_id = :id')
                ->execute(['inst' => $installation->value, 'id' => $id]);
            $insert = $this->pdo->prepare(
                'INSERT INTO destination_subniches (installation_id, destination_id, niche_id, subniche_id) VALUES (:inst, :id, :niche, :sub)'
            );
            foreach (array_unique($subnicheIds) as $sub) {
                $insert->execute(['inst' => $installation->value, 'id' => $id, 'niche' => $nicheId, 'sub' => $sub]);
            }
        });
    }

    public function setPaused(InstallationId $installation, int $id, bool $paused, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE destinations SET user_paused = :paused, updated_at = :at WHERE installation_id = :inst AND id = :id')
            ->execute(['paused' => (int) $paused, 'at' => self::ts($now), 'inst' => $installation->value, 'id' => $id]);
    }

    public function setDeclaration(InstallationId $installation, int $id, string $kind, bool $declared, int $userId, string $version, \DateTimeImmutable $now): void
    {
        [$flag, $at, $by, $ver] = $kind === DestinationDeclarations::PUBLIC_GROUP
            ? ['public_declared', 'public_declared_at', 'public_declared_by_user_id', 'public_declaration_version']
            : ['media_registered_declared', 'media_declared_at', 'media_declared_by_user_id', 'media_declaration_version'];
        // Ao retirar a declaração, o rótulo é reavaliado ANTES (saveEvaluation) para não violar as CHECKs.
        if (!$declared) {
            $this->pdo->prepare('UPDATE destinations SET status = IF(status = \'active\', \'ineligible\', status), eligibility = \'ineligible\' WHERE installation_id = :inst AND id = :id')
                ->execute(['inst' => $installation->value, 'id' => $id]);
        }
        $this->pdo->prepare(
            "UPDATE destinations SET {$flag} = :declared, {$at} = :at, {$by} = :user, {$ver} = :version, updated_at = :at2
             WHERE installation_id = :inst AND id = :id"
        )->execute([
            'declared' => (int) $declared, 'at' => self::ts($now), 'user' => $userId, 'version' => $version, 'at2' => self::ts($now),
            'inst' => $installation->value, 'id' => $id,
        ]);
    }

    public function saveEvaluation(InstallationId $installation, int $id, Eligibility $eligibility, string $status, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE destinations SET eligibility = :eligibility, ineligible_reason = :reason, tech_blocking_reason = :tech, status = :status, updated_at = :at
             WHERE installation_id = :inst AND id = :id'
        )->execute([
            'eligibility' => $eligibility->label, 'reason' => $eligibility->reason, 'tech' => $eligibility->techBlockingReason,
            'status' => $status, 'at' => self::ts($now), 'inst' => $installation->value, 'id' => $id,
        ]);
    }

    public function remove(InstallationId $installation, int $id): void
    {
        $this->pdo->prepare('DELETE FROM destinations WHERE installation_id = :inst AND id = :id')->execute(['inst' => $installation->value, 'id' => $id]);
    }

    public function markTestSent(InstallationId $installation, int $id, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE destinations SET last_test_sent_at = :at WHERE installation_id = :inst AND id = :id')
            ->execute(['at' => self::ts($now), 'inst' => $installation->value, 'id' => $id]);
    }

    public function nicheOptions(InstallationId $installation): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.id AS niche_id, n.slug AS niche_slug, n.name AS niche_name, s.id, s.slug, s.name
             FROM account_subniches a
             JOIN niches n ON n.id = a.niche_id AND n.active = 1
             JOIN subniches s ON s.id = a.subniche_id AND s.active = 1
             WHERE a.installation_id = :inst
             ORDER BY n.sort, n.name, s.sort, s.name'
        );
        $stmt->execute(['inst' => $installation->value]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $nid = (int) $row['niche_id'];
            $out[$nid] ??= ['id' => $nid, 'slug' => (string) $row['niche_slug'], 'name' => (string) $row['niche_name'], 'subniches' => []];
            $out[$nid]['subniches'][] = ['id' => (int) $row['id'], 'slug' => (string) $row['slug'], 'name' => (string) $row['name']];
        }

        return array_values($out);
    }

    /** @param array<string, mixed> $row */
    private static function availableFromRow(array $row): AvailableDestination
    {
        return new AvailableDestination(
            (string) $row['pick_key'], (string) $row['type'], (string) $row['provider_ref'], (string) $row['name'],
            self::bool($row['tech_we_are_admin']), self::bool($row['tech_join_approval']), self::bool($row['tech_announce_only']),
            self::bool($row['tech_is_community']), $row['participants'] === null ? null : (int) $row['participants'], (bool) $row['registered'],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int>            $subniches
     */
    private static function recordFromRow(array $row, array $subniches): DestinationRecord
    {
        return new DestinationRecord(
            (int) $row['id'], (string) $row['public_key'], (string) $row['type'], (string) $row['provider_ref'], (string) $row['name'],
            $row['niche_id'] === null ? null : (int) $row['niche_id'], $subniches, (string) $row['mode'],
            (string) $row['ws'], (string) $row['we'], (int) $row['interval_minutes'], (bool) $row['user_paused'],
            (string) $row['status'], (string) $row['eligibility'], $row['ineligible_reason'] === null ? null : (string) $row['ineligible_reason'],
            new DestinationFacts(
                (bool) $row['tech_is_channel'], (bool) $row['tech_present'], self::bool($row['tech_we_are_admin']),
                self::bool($row['tech_has_invite_link']), self::bool($row['tech_join_approval']),
                self::bool($row['tech_announce_only']), self::bool($row['tech_is_community']),
            ),
            self::date($row['tech_checked_at']),
            (bool) $row['public_declared'], self::date($row['public_declared_at']),
            $row['public_by_name'] === null ? null : (string) $row['public_by_name'],
            $row['public_declaration_version'] === null ? null : (string) $row['public_declaration_version'],
            (bool) $row['media_registered_declared'], self::date($row['media_declared_at']),
            $row['media_by_name'] === null ? null : (string) $row['media_by_name'],
            $row['media_declaration_version'] === null ? null : (string) $row['media_declaration_version'],
            self::date($row['last_test_sent_at']),
        );
    }

    private function transaction(callable $work): void
    {
        $this->pdo->beginTransaction();
        try {
            $work();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private static function flag(?bool $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function bool(mixed $value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }

    private static function ts(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
