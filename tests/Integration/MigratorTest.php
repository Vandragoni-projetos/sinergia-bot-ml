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
            'affiliate_media_declarations', 'discovery_run_entries', 'discovery_runs', 'installations', 'login_attempts', 'ml_categories',
            'ml_credentials', 'ml_oauth_states', 'schema_migrations', 'user_sessions', 'users',
        ], $tables);

        $second = (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        self::assertSame([], $second, 'Segunda execução não aplica nada.');
        self::assertSame(['0001', '0002', '0003', '0004'], $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN));
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

            self::assertSame(['0002', '0003', '0004'], (new Migrator($pdo, $migrations))->migrate());
            self::assertSame('text', $this->scopesType($pdo));
        } finally {
            unlink($dir . '/0001_foundation.sql');
            rmdir($dir);
        }
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
