<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\Bootstrap;
use GNesting\Core\Container;
use GNesting\Core\Database;
use GNesting\Core\Logger;
use GNesting\Core\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Base para testes com banco: recria o banco de testes uma vez por execução
 * e envolve cada teste numa transação desfeita ao final (testes isolados).
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $migrated = false;

    protected Container $container;
    protected Database $db;

    protected function setUp(): void
    {
        $this->container = Bootstrap::createContainer(dirname(__DIR__, 2));
        // Logs dos testes vão para a pasta temporária, não para storage/logs
        $this->container->set(Logger::class, fn () => new Logger(sys_get_temp_dir() . "/gnesting-test-logs"));
        $this->db = $this->container->get(Database::class);

        if (!self::$migrated) {
            $name = $this->db->databaseName();
            $this->db->serverConnection()->exec(
                "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
            $base = dirname(__DIR__, 2) . '/database';
            $migrator = new Migrator($this->db->pdo(), $base . '/migrations', $base . '/seeds');
            $migrator->dropAllTables();
            $migrator->migrate();
            $migrator->seed();
            self::$migrated = true;
        }

        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /** @param array<string, mixed> $params */
    protected function fetchValue(string $sql, array $params = []): mixed
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}
