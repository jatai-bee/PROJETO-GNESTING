<?php

declare(strict_types=1);

namespace GNesting\Core;

use PDO;
use Throwable;

/**
 * Conexão PDO única, aberta sob demanda.
 * - prepared statements nativos (sem emulação) → proteção contra SQL Injection
 * - exceções em erro, fetch associativo, utf8mb4
 * - sessão MySQL em UTC e modo estrito
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array{host:string,port:int,database:string,username:string,password:string,charset:string,collation:string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect(true);
    }

    /** Conexão sem banco selecionado (usada para criar o banco no migrate). */
    public function serverConnection(): PDO
    {
        return $this->connect(false);
    }

    public function databaseName(): string
    {
        return $this->config['database'];
    }

    /**
     * Executa $callback dentro de uma transação. Se já existir uma transação
     * aberta, apenas participa dela (commit/rollback ficam com quem abriu).
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function connect(bool $withDatabase): PDO
    {
        $c = $this->config;
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], $c['port'], $c['charset']);
        if ($withDatabase) {
            $dsn .= ';dbname=' . $c['database'];
        }

        $pdo = new PDO($dsn, $c['username'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        $pdo->exec("SET NAMES {$c['charset']} COLLATE {$c['collation']}");
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return $pdo;
    }
}
