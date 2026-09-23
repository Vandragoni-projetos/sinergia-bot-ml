<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use Sinergia\Application\Port\MercadoLivre\Category;
use Sinergia\Application\Port\MercadoLivre\CategoryRef;
use Sinergia\Application\Port\MercadoLivre\HighlightEntry;
use Sinergia\Application\Port\MercadoLivre\TokenSet;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\DiscoveryRunRepository;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\MlCategoryRepository;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Infrastructure\Persistence\OAuthStateRepository;
use Sinergia\Shared\Config\SensitiveValue;

final class RepositoriesTest extends DatabaseTestCase
{
    public function testInstallationEnsureIsIdempotent(): void
    {
        $repo = new InstallationRepository($this->freshSchema());

        $a = $repo->ensure('default', 'Local', 'MLB');
        $b = $repo->ensure('default', 'Outro nome', 'MLB');

        self::assertTrue($a->id->equals($b->id));
        self::assertSame('Local', $b->name, 'ensure não sobrescreve instalação existente');
        self::assertTrue($a->isActive());
    }

    public function testDiscoveryRunLifecycleAndEntries(): void
    {
        $pdo = $this->freshSchema();
        $inst = (new InstallationRepository($pdo))->ensure('default', 'Local', 'MLB')->id;
        $runs = new DiscoveryRunRepository($pdo);
        $now = new \DateTimeImmutable('2026-09-22T12:00:00Z');

        $runId = $runs->start($inst, '11111111-1111-4111-8111-111111111111', 'highlights_validation', 'MLB', 'MLB10', 'oauth', $now);
        $runs->addEntries($inst, $runId, [new HighlightEntry('MLB1', 1, 'ITEM'), new HighlightEntry('MLBU2', 2, 'USER_PRODUCT')]);
        $runs->finish($inst, $runId, 'succeeded', $now, 200, 2, 120, headers: ['date' => 'x'], warnings: ['aviso']);

        $row = $runs->find($inst, $runId);
        self::assertSame('succeeded', $row['status']);
        self::assertSame(2, (int) $row['items_found']);
        self::assertSame([['position' => 1, 'ml_entity_id' => 'MLB1', 'ml_entity_type' => 'ITEM'], ['position' => 2, 'ml_entity_id' => 'MLBU2', 'ml_entity_type' => 'USER_PRODUCT']], array_map(
            static fn (array $r): array => ['position' => (int) $r['position'], 'ml_entity_id' => $r['ml_entity_id'], 'ml_entity_type' => $r['ml_entity_type']],
            $runs->entries($inst, $runId),
        ));
    }

    public function testEntryCannotPointToRunOfAnotherInstallation(): void
    {
        $pdo = $this->freshSchema();
        $repo = new InstallationRepository($pdo);
        $a = $repo->ensure('inst-a', 'A', 'MLB')->id;
        $b = $repo->ensure('inst-b', 'B', 'MLB')->id;
        $runs = new DiscoveryRunRepository($pdo);
        $runA = $runs->start($a, '22222222-2222-4222-8222-222222222222', 'highlights_validation', 'MLB', 'MLB10', 'oauth', new \DateTimeImmutable());

        $this->expectException(\PDOException::class);
        $runs->addEntries($b, $runA, [new HighlightEntry('MLB1', 1, 'ITEM')]);
    }

    public function testRunsAreScopedByInstallation(): void
    {
        $pdo = $this->freshSchema();
        $repo = new InstallationRepository($pdo);
        $a = $repo->ensure('inst-a', 'A', 'MLB')->id;
        $b = $repo->ensure('inst-b', 'B', 'MLB')->id;
        $runs = new DiscoveryRunRepository($pdo);
        $runA = $runs->start($a, '33333333-3333-4333-8333-333333333333', 'category_validation', 'MLB', null, 'none', new \DateTimeImmutable());

        self::assertNotNull($runs->find($a, $runA));
        self::assertNull($runs->find($b, $runA), 'Instalação B não enxerga execução da A');
    }

    public function testCredentialsAreEncryptedAtRest(): void
    {
        $pdo = $this->freshSchema();
        $inst = (new InstallationRepository($pdo))->ensure('default', 'Local', 'MLB')->id;
        $box = new SecretBox(new SensitiveValue((string) base64_decode(SecretBox::generateKeyBase64(), true)));
        $repo = new MlCredentialRepository($pdo, $box);
        $now = new \DateTimeImmutable('2026-09-22T12:00:00Z');

        $repo->save($inst, '123456', new TokenSet(
            new SensitiveValue('APP_USR-access-plain'), new SensitiveValue('TG-refresh-plain'), 21600, 'offline_access read', 42, 'bearer',
        ), $now);

        $raw = $pdo->query('SELECT access_token_enc, refresh_token_enc FROM ml_credentials')->fetch();
        self::assertStringNotContainsString('APP_USR-access-plain', (string) $raw['access_token_enc']);
        self::assertStringNotContainsString('TG-refresh-plain', (string) $raw['refresh_token_enc']);

        $stored = $repo->find($inst);
        self::assertSame('APP_USR-access-plain', $stored?->accessToken->reveal());
        self::assertSame('TG-refresh-plain', $stored?->refreshToken?->reveal());
        self::assertSame('2026-09-22 18:00:00', $stored?->accessExpiresAt->format('Y-m-d H:i:s'));
    }

    public function testOAuthStateIsSingleUseAndExpires(): void
    {
        $pdo = $this->freshSchema();
        $inst = (new InstallationRepository($pdo))->ensure('default', 'Local', 'MLB')->id;
        $box = new SecretBox(new SensitiveValue((string) base64_decode(SecretBox::generateKeyBase64(), true)));
        $states = new OAuthStateRepository($pdo, $box);
        $now = new \DateTimeImmutable('2026-09-22T12:00:00Z');

        $states->create($inst, new SensitiveValue('state-1'), new SensitiveValue('verifier-1'), $now->modify('+10 minutes'));
        self::assertStringNotContainsString('state-1', (string) json_encode($pdo->query('SELECT * FROM ml_oauth_states')->fetchAll()));
        self::assertSame('verifier-1', $states->consume($inst, new SensitiveValue('state-1'), $now)['verifier']?->reveal());

        try {
            $states->consume($inst, new SensitiveValue('state-1'), $now);
            self::fail('State não pode ser reutilizado.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('já utilizado', $e->getMessage());
        }

        $states->create($inst, new SensitiveValue('state-2'), null, $now->modify('+10 minutes'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expirado');
        $states->consume($inst, new SensitiveValue('state-2'), $now->modify('+11 minutes'));
    }

    public function testCategoryCacheUpsert(): void
    {
        $pdo = $this->freshSchema();
        $repo = new MlCategoryRepository($pdo);
        $category = new Category(
            'MLB900002', 'Folha', [new CategoryRef('MLB900000', 'Raiz'), new CategoryRef('MLB900002', 'Folha')], [],
            'MLB-X', 10, null, new \DateTimeImmutable('2026-09-22T12:00:00Z'),
        );

        $repo->upsert('MLB', $category, $category->fetchedAt);
        $repo->upsert('MLB', $category, $category->fetchedAt);
        $row = $repo->find('MLB', 'MLB900002');

        self::assertSame('MLB900000', $row['parent_category_id']);
        self::assertSame(1, (int) $row['is_leaf']);
        self::assertSame('MLB-X', $row['catalog_domain']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM ml_categories')->fetchColumn());
    }
}
