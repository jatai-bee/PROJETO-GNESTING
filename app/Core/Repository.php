<?php

declare(strict_types=1);

namespace GNesting\Core;

use PDOStatement;

/**
 * Base dos repositórios: único lugar da aplicação onde há SQL.
 * Sempre com parâmetros (prepared statements). Com prepares nativos,
 * cada parâmetro nomeado só pode aparecer UMA vez por consulta.
 */
abstract class Repository
{
    public function __construct(protected readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string, mixed> $params */
    protected function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $params
     * @return int linhas afetadas
     */
    protected function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @param array<string, mixed> $params
     * @return int id gerado
     */
    protected function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->db->pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $statement->bindValue(
                ':' . ltrim((string) $name, ':'),
                $value,
                match (true) {
                    is_int($value) => \PDO::PARAM_INT,
                    is_bool($value) => \PDO::PARAM_BOOL,
                    $value === null => \PDO::PARAM_NULL,
                    default => \PDO::PARAM_STR,
                }
            );
        }
        $statement->execute();

        return $statement;
    }
}
