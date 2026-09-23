<?php

declare(strict_types=1);

namespace GNesting\Core\Migrations;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Aplica em ordem os arquivos database/migrations/NNN_*.sql ainda não registrados
 * em schema_migrations. Seeds são registrados como "seed:NNN_nome".
 *
 * Atenção: no MySQL, comandos DDL (CREATE/ALTER/DROP) fazem commit implícito,
 * então uma migration que falhe no meio pode ficar parcialmente aplicada.
 * Mantenha cada migration pequena e teste antes em um banco local.
 */
final class Migrator
{
    /** @var callable(string): void */
    private $output;

    /** @param callable(string): void|null $output */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
        private readonly string $seedsPath,
        ?callable $output = null,
    ) {
        $this->output = $output ?? static function (string $line): void {
        };
    }

    /** @return list<string> nomes das migrations aplicadas agora */
    public function migrate(): array
    {
        return $this->applyPending($this->files($this->migrationsPath), '');
    }

    /** @return list<string> */
    public function seed(): array
    {
        return $this->applyPending($this->files($this->seedsPath), 'seed:');
    }

    /** Remove TODAS as tabelas do banco atual. Somente para desenvolvimento/testes. */
    public function dropAllTables(): void
    {
        $tables = $this->pdo->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $this->pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $table) . '`');
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        ($this->output)('Tabelas removidas: ' . count($tables));
    }

    /** @return array<string, bool> nome => aplicada? */
    public function status(): array
    {
        $applied = array_flip($this->applied());
        $status = [];
        foreach ($this->files($this->migrationsPath) as $name => $file) {
            $status[$name] = isset($applied[$name]);
        }
        foreach ($this->files($this->seedsPath) as $name => $file) {
            $status['seed:' . $name] = isset($applied['seed:' . $name]);
        }

        return $status;
    }

    /**
     * @param array<string, string> $files
     * @return list<string>
     */
    private function applyPending(array $files, string $prefix): array
    {
        $this->ensureTable();
        $applied = array_flip($this->applied());
        $ran = [];

        foreach ($files as $name => $file) {
            $key = $prefix . $name;
            if (isset($applied[$key])) {
                continue;
            }

            ($this->output)("Aplicando {$key}...");
            $sql = (string) file_get_contents($file);
            foreach (SqlSplitter::split($sql) as $index => $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw new RuntimeException(
                        "Falha em {$key}, comando #" . ($index + 1) . ': ' . $e->getMessage()
                        . "\n" . mb_substr($statement, 0, 300),
                        0,
                        $e
                    );
                }
            }

            $insert = $this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
            $insert->execute(['migration' => $key]);
            $ran[] = $key;
        }

        return $ran;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration  VARCHAR(190) NOT NULL,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> */
    private function applied(): array
    {
        $exists = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
        )->fetchColumn();

        if ((int) $exists === 0) {
            return [];
        }

        return $this->pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string, string> nome => caminho, em ordem */
    private function files(string $path): array
    {
        $files = [];
        foreach (glob(rtrim($path, '/\\') . '/*.sql') ?: [] as $file) {
            $files[basename($file, '.sql')] = $file;
        }
        ksort($files, SORT_STRING);

        return $files;
    }
}
