<?php

declare(strict_types=1);

namespace GNesting\Services\Operations;

use GNesting\Core\Database;
use GNesting\Core\Migrations\SqlSplitter;
use PDO;
use RuntimeException;

/**
 * Cópia do banco em SQL comprimido (.sql.gz), feita só com PDO: a hospedagem compartilhada nem
 * sempre libera mysqldump/exec. O arquivo também restaura com qualquer cliente MySQL
 * (gunzip < database.sql.gz | mysql banco).
 */
final class DatabaseDumper
{
    private const ROWS_PER_INSERT = 200;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, int> linhas por tabela
     */
    public function dump(string $gzPath): array
    {
        $pdo = $this->db->pdo();
        $gz = gzopen($gzPath, 'wb6') ?: throw new RuntimeException("Não foi possível criar {$gzPath}.");
        $counts = [];

        try {
            gzwrite($gz, "-- G-Nesting: cópia do banco `{$this->db->databaseName()}` em " . gmdate('Y-m-d H:i:s') . " UTC\n"
                . "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $quoted = '`' . str_replace('`', '``', (string) $table) . '`';
                $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM)[1];
                gzwrite($gz, "DROP TABLE IF EXISTS {$quoted};\n{$create};\n");
                $counts[(string) $table] = $this->dumpRows($pdo, $gz, $quoted);
                gzwrite($gz, "\n");
            }

            gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } finally {
            gzclose($gz);
        }

        return $counts;
    }

    /** Recria as tabelas e os dados a partir de um dump. Apaga o conteúdo atual das tabelas presentes no arquivo. */
    public function restore(string $gzPath): int
    {
        $sql = '';
        $gz = gzopen($gzPath, 'rb') ?: throw new RuntimeException("Não foi possível ler {$gzPath}.");
        try {
            while (!gzeof($gz)) {
                $sql .= (string) gzread($gz, 1 << 20);
            }
        } finally {
            gzclose($gz);
        }

        $pdo = $this->db->pdo();
        $statements = SqlSplitter::split($sql);
        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return count($statements);
    }

    /** @param resource $gz */
    private function dumpRows(PDO $pdo, $gz, string $quotedTable): int
    {
        $rows = 0;
        $batch = [];
        $columns = null;
        foreach ($pdo->query("SELECT * FROM {$quotedTable}", PDO::FETCH_ASSOC) as $row) {
            $columns ??= '(' . implode(', ', array_map(fn ($c) => '`' . str_replace('`', '``', (string) $c) . '`', array_keys($row))) . ')';
            $batch[] = '(' . implode(', ', array_map(fn ($v) => $this->literal($pdo, $v), $row)) . ')';
            $rows++;
            if (count($batch) === self::ROWS_PER_INSERT) {
                gzwrite($gz, "INSERT INTO {$quotedTable} {$columns} VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch !== []) {
            gzwrite($gz, "INSERT INTO {$quotedTable} {$columns} VALUES\n" . implode(",\n", $batch) . ";\n");
        }

        return $rows;
    }

    private function literal(PDO $pdo, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $pdo->quote((string) $value),
        };
    }
}
