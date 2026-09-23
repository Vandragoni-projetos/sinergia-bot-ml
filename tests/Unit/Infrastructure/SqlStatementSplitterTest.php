<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Sinergia\Infrastructure\Database\SqlStatementSplitter;

final class SqlStatementSplitterTest extends TestCase
{
    public function testSplitsRespectingQuotesAndComments(): void
    {
        $sql = <<<'SQL'
            -- comentário; com ponto e vírgula
            CREATE TABLE a (x VARCHAR(10) DEFAULT 'a;b');
            /* bloco ; */ INSERT INTO a VALUES ("c;d");
            # outro comentário;
            SELECT `weird;name` FROM a
            SQL;

        $statements = SqlStatementSplitter::split($sql);

        self::assertCount(3, $statements);
        self::assertStringContainsString("'a;b'", $statements[0]);
        self::assertStringContainsString('"c;d"', $statements[1]);
        self::assertStringContainsString('`weird;name`', $statements[2]);
    }

    public function testRealMigrationSplitsIntoCreateStatements(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/database/migrations/0001_foundation.sql');
        $statements = SqlStatementSplitter::split($sql);

        self::assertCount(6, $statements);
        foreach ($statements as $statement) {
            self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS', $statement);
        }
    }
}
