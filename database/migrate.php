<?php

declare(strict_types=1);

/*
 * G-Nesting — migrations
 *
 *   php database/migrate.php                aplica migrations pendentes
 *   php database/migrate.php --seed         aplica migrations e seeds pendentes
 *   php database/migrate.php --status       lista o que foi aplicado
 *   php database/migrate.php --create-db    cria o banco (se não existir) antes de migrar
 *   php database/migrate.php --fresh        APAGA todas as tabelas e recria (bloqueado em produção)
 */

use GNesting\Core\Bootstrap;
use GNesting\Core\Config;
use GNesting\Core\Database;
use GNesting\Core\Migrations\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$options = getopt('', ['seed', 'status', 'fresh', 'create-db', 'force']);
$out = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

try {
    $container = Bootstrap::createContainer($basePath);
    $config = $container->get(Config::class);
    $db = $container->get(Database::class);
    $env = (string) $config->get('app.env');

    if (isset($options['create-db'])) {
        $name = $db->databaseName();
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException("Nome de banco inválido: {$name}");
        }
        $db->serverConnection()->exec(
            "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $out("Banco '{$name}' pronto.");
    }

    $migrator = new Migrator(
        $db->pdo(),
        $basePath . '/database/migrations',
        $basePath . '/database/seeds',
        $out,
    );

    if (isset($options['status'])) {
        foreach ($migrator->status() as $name => $applied) {
            $out(($applied ? '[x] ' : '[ ] ') . $name);
        }
        exit(0);
    }

    if (isset($options['fresh'])) {
        if ($env === 'production') {
            throw new RuntimeException('--fresh é bloqueado em produção.');
        }
        $migrator->dropAllTables();
    }

    $ran = $migrator->migrate();
    if (isset($options['seed']) || isset($options['fresh'])) {
        $ran = [...$ran, ...$migrator->seed()];
    }

    $out($ran === [] ? 'Nada a aplicar: banco atualizado.' : 'Concluído: ' . implode(', ', $ran));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
