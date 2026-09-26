<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Onboarding\OnboardingFacts;
use Sinergia\Application\Port\Onboarding\OnboardingStore;
use Sinergia\Domain\Installation\InstallationId;

/** Fatos do onboarding. TODA consulta filtra por installation_id. */
final class OnboardingRepository implements OnboardingStore
{
    public function __construct(private readonly \PDO $pdo, private readonly string $siteId)
    {
    }

    public function facts(InstallationId $installation): OnboardingFacts
    {
        $latestRun = '(SELECT MAX(id) FROM offer_selection_runs WHERE installation_id = :inst2 AND status IN (\'completed\', \'partial\'))';
        $last = $this->value('SELECT MAX(started_at) FROM offer_selection_runs WHERE installation_id = :inst', $installation);

        return new OnboardingFacts(
            mercadoLivreConnected: $this->value('SELECT status FROM ml_credentials WHERE installation_id = :inst', $installation) === 'connected',
            mediaDeclared: (int) $this->value('SELECT COUNT(*) FROM affiliate_media_declarations WHERE installation_id = :inst AND revoked_at IS NULL', $installation) > 0,
            activeSubniches: (int) $this->value(
                'SELECT COUNT(DISTINCT a.subniche_id) FROM account_subniches a
                 JOIN niches n ON n.id = a.niche_id AND n.active = 1
                 JOIN subniches s ON s.id = a.subniche_id AND s.active = 1
                 WHERE a.installation_id = :inst
                   AND EXISTS (SELECT 1 FROM subniche_categories c WHERE c.subniche_id = s.id AND c.site_id = :site AND c.status = \'approved\')',
                $installation,
                ['site' => $this->siteId],
            ),
            whatsAppConnected: (int) $this->value('SELECT COUNT(*) FROM whatsapp_connections WHERE installation_id = :inst AND status = \'connected\' AND instance_token_enc IS NOT NULL', $installation) > 0,
            destinations: (int) $this->value('SELECT COUNT(*) FROM destinations WHERE installation_id = :inst', $installation),
            readyDestinations: (int) $this->value(
                'SELECT COUNT(*) FROM destinations d WHERE d.installation_id = :inst AND d.status = \'active\' AND d.eligibility <> \'ineligible\'
                   AND d.niche_id IS NOT NULL AND EXISTS (SELECT 1 FROM destination_subniches s WHERE s.installation_id = d.installation_id AND s.destination_id = d.id)',
                $installation,
            ),
            candidates: (int) $this->value('SELECT COUNT(DISTINCT ml_product_id) FROM account_offer_candidates WHERE installation_id = :inst AND run_id = ' . $latestRun, $installation, ['inst2' => $installation->value]),
            candidatesWithLink: (int) $this->value(
                'SELECT COUNT(DISTINCT c.ml_product_id) FROM account_offer_candidates c
                 JOIN affiliate_links l ON l.installation_id = c.installation_id AND l.active_product_id = c.ml_product_id
                 WHERE c.installation_id = :inst AND c.run_id = ' . $latestRun,
                $installation,
                ['inst2' => $installation->value],
            ),
            botActive: $this->value('SELECT bot_status FROM installations WHERE id = :inst', $installation) === 'active',
            lastOfferSearch: $last === null ? null : new \DateTimeImmutable((string) $last, new \DateTimeZone('UTC')),
        );
    }

    public function activate(InstallationId $installation, int $userId, \DateTimeImmutable $now): void
    {
        $at = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        $this->pdo->prepare(
            'UPDATE installations SET bot_status = \'active\', bot_status_changed_at = :at, bot_status_changed_by = :user,
                onboarding_completed_at = COALESCE(onboarding_completed_at, :at2), onboarding_completed_by = COALESCE(onboarding_completed_by, :user2)
             WHERE id = :inst'
        )->execute(['at' => $at, 'user' => $userId, 'at2' => $at, 'user2' => $userId, 'inst' => $installation->value]);
    }

    /** @param array<string, scalar> $extra */
    private function value(string $sql, InstallationId $installation, array $extra = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['inst' => $installation->value] + $extra);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }
}
