<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Core\Migrations\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsStatementsAndRemovesComments(): void
    {
        $sql = <<<'SQL'
            -- comentário; com ponto e vírgula
            CREATE TABLE a (id INT); # outro comentário
            /* bloco; */ INSERT INTO a VALUES (1);
            SQL;

        self::assertSame(['CREATE TABLE a (id INT)', 'INSERT INTO a VALUES (1)'], SqlSplitter::split($sql));
    }

    public function testKeepsSemicolonsAndCommentMarkersInsideStrings(): void
    {
        $statements = SqlSplitter::split("INSERT INTO t VALUES ('a;b -- c', \"d;e\", 'it''s', 'x\\'y;z'); SELECT 1");

        self::assertCount(2, $statements);
        self::assertStringContainsString("'a;b -- c'", $statements[0]);
        self::assertStringContainsString("'x\\'y;z'", $statements[0]);
        self::assertSame('SELECT 1', $statements[1]);
    }

    public function testRealMigrationAndSeedSplitIntoExpectedStatements(): void
    {
        $base = dirname(__DIR__, 2) . '/database';
        $schema = SqlSplitter::split((string) file_get_contents($base . '/migrations/001_initial_schema.sql'));
        $creates = array_filter($schema, static fn (string $s) => str_starts_with($s, 'CREATE TABLE'));

        self::assertCount(38, $creates);
        self::assertNotEmpty(SqlSplitter::split((string) file_get_contents($base . '/seeds/001_seed.sql')));
    }
}
