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
            'discovery_run_entries', 'discovery_runs', 'installations', 'ml_categories',
            'ml_credentials', 'ml_oauth_states', 'schema_migrations',
        ], $tables);

        $second = (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        self::assertSame([], $second, 'Segunda execução não aplica nada.');
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
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
