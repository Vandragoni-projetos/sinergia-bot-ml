<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sinergia\Infrastructure\Database\ConnectionFactory;
use Sinergia\Infrastructure\Database\Migrator;
use Sinergia\Shared\Config\Config;

/**
 * Base dos testes que usam MariaDB real (banco EXCLUSIVO de testes, via TEST_DB_*).
 * Cada classe recria o schema do zero. Sem TEST_DB_* os testes são marcados como pulados.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $env = array_merge($_ENV, getenv());
        $env['APP_ENV'] = 'test';
        $config = Config::fromArray(array_filter($env, 'is_string'));
        if (!$config->hasDatabase('TEST_DB_')) {
            self::markTestSkipped('TEST_DB_* não configurado: teste de banco pulado.');
        }
        if (self::$pdo === null) {
            $db = $config->database('TEST_DB_');
            if (!str_contains($db->database, 'test')) {
                self::fail('Por segurança, TEST_DB_DATABASE precisa conter "test" no nome.');
            }
            self::$pdo = ConnectionFactory::create($db);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo = null;
    }

    protected function freshSchema(): \PDO
    {
        $pdo = self::$pdo ?? throw new \LogicException('Sem conexão.');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_NUM) as $row) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $row[0]) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();

        return $pdo;
    }
}
