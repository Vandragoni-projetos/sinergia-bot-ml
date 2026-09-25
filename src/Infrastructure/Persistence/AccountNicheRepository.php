<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Niche\AccountNicheSelection;
use Sinergia\Application\Niche\NicheFilters;
use Sinergia\Application\Port\Niche\AccountNicheStore;
use Sinergia\Domain\Installation\InstallationId;

/** Preferências privadas de nichos. TODA consulta filtra por installation_id. */
final class AccountNicheRepository implements AccountNicheStore
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function selections(InstallationId $installation): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT niche_id, min_discount_pct, min_price, max_price, require_photo
             FROM account_niches WHERE installation_id = :inst'
        );
        $stmt->execute(['inst' => $installation->value]);
        $rows = $stmt->fetchAll();

        $subs = $this->pdo->prepare(
            'SELECT niche_id, subniche_id FROM account_subniches WHERE installation_id = :inst ORDER BY subniche_id'
        );
        $subs->execute(['inst' => $installation->value]);
        $byNiche = [];
        foreach ($subs->fetchAll() as $sub) {
            $byNiche[(int) $sub['niche_id']][] = (int) $sub['subniche_id'];
        }

        $result = [];
        foreach ($rows as $row) {
            $nicheId = (int) $row['niche_id'];
            $result[$nicheId] = new AccountNicheSelection(
                $nicheId,
                $byNiche[$nicheId] ?? [],
                new NicheFilters(
                    $row['min_discount_pct'] === null ? null : (int) $row['min_discount_pct'],
                    self::toCents($row['min_price']),
                    self::toCents($row['max_price']),
                    (bool) $row['require_photo'],
                ),
            );
        }

        return $result;
    }

    public function save(
        InstallationId $installation,
        int $nicheId,
        array $subnicheIds,
        NicheFilters $filters,
        int $userId,
        \DateTimeImmutable $now,
    ): void {
        $at = $now->format('Y-m-d H:i:s.v');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO account_niches
                    (installation_id, niche_id, min_discount_pct, min_price, max_price, require_photo, updated_at, updated_by_user_id)
                 VALUES (:inst, :niche, :discount, :min, :max, :photo, :at, :user)
                 ON DUPLICATE KEY UPDATE
                    min_discount_pct = VALUES(min_discount_pct), min_price = VALUES(min_price), max_price = VALUES(max_price),
                    require_photo = VALUES(require_photo), updated_at = VALUES(updated_at), updated_by_user_id = VALUES(updated_by_user_id)'
            )->execute([
                'inst' => $installation->value,
                'niche' => $nicheId,
                'discount' => $filters->minDiscountPct,
                'min' => self::toDecimal($filters->minPriceCents),
                'max' => self::toDecimal($filters->maxPriceCents),
                'photo' => $filters->requirePhoto ? 1 : 0,
                'at' => $at,
                'user' => $userId,
            ]);

            // Remove só o que foi desmarcado (preserva enabled_at dos que continuam ativos).
            $keep = array_values(array_map('intval', $subnicheIds));
            $params = ['inst' => $installation->value, 'niche' => $nicheId];
            $notIn = '';
            if ($keep !== []) {
                $placeholders = [];
                foreach ($keep as $i => $id) {
                    $placeholders[] = ':k' . $i;
                    $params['k' . $i] = $id;
                }
                $notIn = ' AND subniche_id NOT IN (' . implode(', ', $placeholders) . ')';
            }
            $this->pdo->prepare('DELETE FROM account_subniches WHERE installation_id = :inst AND niche_id = :niche' . $notIn)->execute($params);

            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO account_subniches (installation_id, niche_id, subniche_id, enabled_at, enabled_by_user_id)
                 VALUES (:inst, :niche, :sub, :at, :user)'
            );
            foreach ($keep as $id) {
                $insert->execute(['inst' => $installation->value, 'niche' => $nicheId, 'sub' => $id, 'at' => $at, 'user' => $userId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private static function toCents(mixed $decimal): ?int
    {
        if ($decimal === null) {
            return null;
        }
        [$int, $frac] = array_pad(explode('.', (string) $decimal), 2, '0');

        return (int) $int * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
    }

    private static function toDecimal(?int $cents): ?string
    {
        return $cents === null ? null : sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
