<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use Sinergia\Infrastructure\Database\MigrationException;
use Sinergia\Infrastructure\Database\Migrator;

final class MigratorTest extends DatabaseTestCase
{
    public function testMigrationCreatesExpectedTablesAndIsIdempotent(): void
    {
        $pdo = $this->freshSchema();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        sort($tables);

        self::assertSame([
            'account_niches', 'account_offer_candidates', 'account_subniches', 'affiliate_link_batch_items', 'affiliate_link_batch_lines', 'affiliate_link_batches', 'affiliate_links',
            'affiliate_media_declarations', 'destination_subniches', 'destinations', 'discovery_run_entries', 'discovery_runs', 'dispatch_attempts', 'dispatch_queue', 'installations',
            'login_attempts', 'ml_categories', 'ml_credentials', 'ml_oauth_states',
            'ml_product_offers', 'ml_products', 'ml_ranking_entries', 'ml_ranking_snapshots', 'niches', 'offer_selection_runs', 'schema_migrations', 'subniche_categories', 'subniches', 'user_sessions', 'users', 'whatsapp_available_destinations', 'whatsapp_connections', 'worker_heartbeats',
        ], $tables);

        $second = (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        self::assertSame([], $second, 'Segunda execução não aplica nada.');
        self::assertSame(['0001', '0002', '0003', '0004', '0005', '0006', '0007', '0008', '0009', '0010', '0011', '0012'], $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testDatabaseWithOnly0001IsUpgradedTo0002(): void
    {
        // Cenário da produção: 0001 já aplicada; a 0002 só amplia ml_credentials.scopes.
        $pdo = $this->freshSchema();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_NUM) as $row) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $row[0]) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $migrations = dirname(__DIR__, 2) . '/database/migrations';
        $dir = sys_get_temp_dir() . '/sinergia-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        copy($migrations . '/0001_foundation.sql', $dir . '/0001_foundation.sql');

        try {
            self::assertSame(['0001'], (new Migrator($pdo, $dir))->migrate());
            self::assertSame('varchar', $this->scopesType($pdo));

            self::assertSame(['0002', '0003', '0004', '0005', '0006', '0007', '0008', '0009', '0010', '0011', '0012'], (new Migrator($pdo, $migrations))->migrate());
            self::assertSame('text', $this->scopesType($pdo));
        } finally {
            unlink($dir . '/0001_foundation.sql');
            rmdir($dir);
        }
    }

    public function testProductionLikeDatabaseAt0002WithDataIsUpgradedToCurrent(): void
    {
        // Cenário da produção: 0001–0002 aplicadas, instalação conectada ao ML e um state OAuth antigo.
        $pdo = $this->emptySchema();
        $migrations = dirname(__DIR__, 2) . '/database/migrations';
        $dir = $this->dirWith(['0001', '0002']);
        try {
            self::assertSame(['0001', '0002'], (new Migrator($pdo, $dir))->migrate());
        } finally {
            $this->removeDir($dir);
        }
        $pdo->exec("INSERT INTO installations (slug, name) VALUES ('default', 'Produção')");
        $pdo->exec("INSERT INTO ml_credentials (installation_id, client_id, access_token_enc, key_id, access_expires_at) VALUES (1, 'x', UNHEX('00'), 'k1', UTC_TIMESTAMP(3))");
        $pdo->exec("INSERT INTO ml_oauth_states (installation_id, state_hash, expires_at) VALUES (1, REPEAT('a', 64), UTC_TIMESTAMP(3))");

        self::assertSame(['0003', '0004', '0005', '0006', '0007', '0008', '0009', '0010', '0011', '0012'], (new Migrator($pdo, $migrations))->migrate());
        self::assertSame([['default', 'active', 'paused', 'manual_batch', null]], $pdo->query('SELECT slug, status, bot_status, affiliate_mode, onboarding_completed_at FROM installations')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame('connected', $pdo->query('SELECT status FROM ml_credentials')->fetchColumn());
        self::assertSame('cli', $pdo->query('SELECT origin FROM ml_oauth_states')->fetchColumn());
        self::assertSame(['4', '16', '65'], [
            (string) $pdo->query('SELECT COUNT(*) FROM niches')->fetchColumn(),
            (string) $pdo->query('SELECT COUNT(*) FROM subniches')->fetchColumn(),
            (string) $pdo->query('SELECT COUNT(*) FROM subniche_categories')->fetchColumn(),
        ]);
        self::assertSame([], (new Migrator($pdo, $migrations))->migrate(), 'Reexecução não aplica nada.');
    }

    /** @return iterable<string, array{0: string, 1: int, 2: list<string>, 3: string}> */
    public static function partiallyAppliedMigrations(): iterable
    {
        // [versão, quantas instruções "passaram" antes da falha, CHECKs criadas por elas, INSERT que a CHECK deve barrar]
        yield '0004 depois dos dois ALTER' => ['0004', 2, ['ck_ml_oauth_states_origin', 'ck_installations_affiliate_mode'],
            "INSERT INTO installations (slug, name, affiliate_mode) VALUES ('x', 'X', 'cookie')"];
        yield '0004 depois do primeiro ALTER' => ['0004', 1, ['ck_ml_oauth_states_origin'],
            "INSERT INTO ml_oauth_states (installation_id, state_hash, expires_at, origin) VALUES (1, REPEAT('b', 64), UTC_TIMESTAMP(3), 'web')"];
        yield '0011 depois do ALTER' => ['0011', 1, ['ck_installations_bot_status'],
            "INSERT INTO installations (slug, name, bot_status) VALUES ('x', 'X', 'on')"];
    }

    /**
     * DDL no MariaDB não é transacional: se a migration cai DEPOIS de criar a CHECK, a versão não é registrada e a
     * reexecução precisa passar sem intervenção manual (sem erro 1826 de CHECK duplicada).
     *
     * @param list<string> $checks
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('partiallyAppliedMigrations')]
    public function testPartiallyAppliedMigrationResumesWithoutManualIntervention(string $version, int $applied, array $checks, string $violation): void
    {
        $pdo = $this->emptySchema();
        $migrations = dirname(__DIR__, 2) . '/database/migrations';
        $before = array_values(array_filter(
            array_map(static fn (string $f): string => substr(basename($f), 0, 4), (array) glob($migrations . '/*.sql')),
            static fn (string $v): bool => $v < $version,
        ));
        $dir = $this->dirWith($before);
        try {
            (new Migrator($pdo, $dir))->migrate();
        } finally {
            $this->removeDir($dir);
        }
        $pdo->exec("INSERT INTO installations (slug, name) VALUES ('default', 'Produção')");
        $file = (string) (glob($migrations . '/' . $version . '_*.sql')[0] ?? '');
        foreach (array_slice(\Sinergia\Infrastructure\Database\SqlStatementSplitter::split((string) file_get_contents($file)), 0, $applied) as $statement) {
            $pdo->exec($statement);   // simula a queda logo depois destas instruções
        }
        foreach ($checks as $check) {
            self::assertSame(1, $this->checkCount($pdo, $check), $check . ' já existe antes da reexecução');
        }
        self::assertNotContains($version, $pdo->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN));

        $done = (new Migrator($pdo, $migrations))->migrate();

        self::assertSame($version, $done[0] ?? null);
        self::assertSame('0012', $done[count($done) - 1]);
        foreach ($checks as $check) {
            self::assertSame(1, $this->checkCount($pdo, $check), $check . ' continua única');
        }
        try {
            $pdo->exec($violation);
            self::fail('A CHECK deveria continuar valendo.');
        } catch (\PDOException $e) {
            self::assertSame('23000', (string) $e->getCode());
        }
    }

    private function checkCount(\PDO $pdo, string $name): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    private function emptySchema(): \PDO
    {
        $pdo = $this->freshSchema();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_NUM) as $row) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $row[0]) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return $pdo;
    }

    /** @param list<string> $versions */
    private function dirWith(array $versions): string
    {
        $dir = sys_get_temp_dir() . '/sinergia-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach ((array) glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') as $file) {
            if (in_array(substr(basename((string) $file), 0, 4), $versions, true)) {
                copy((string) $file, $dir . '/' . basename((string) $file));
            }
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        foreach ((array) glob($dir . '/*') as $file) {
            unlink((string) $file);
        }
        rmdir($dir);
    }

    private function scopesType(\PDO $pdo): string
    {
        return (string) $pdo->query(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ml_credentials' AND COLUMN_NAME = 'scopes'"
        )->fetchColumn();
    }

    public function testChangedAppliedMigrationIsRejected(): void
    {
        $pdo = $this->freshSchema();
        $dir = sys_get_temp_dir() . '/sinergia-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $original = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/0001_foundation.sql');
        file_put_contents($dir . '/0001_foundation.sql', $original . "\n-- alterado\n");

        try {
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('foi alterada');
            (new Migrator($pdo, $dir))->migrate();
        } finally {
            unlink($dir . '/0001_foundation.sql');
            rmdir($dir);
        }
    }

    public function testCheckConstraintsAreEnforced(): void
    {
        $pdo = $this->freshSchema();

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO installations (slug, name, status) VALUES ('x', 'X', 'hacked')");
    }
}
