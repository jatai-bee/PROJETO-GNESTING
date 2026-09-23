<?php

declare(strict_types=1);

/*
 * Os testes rodam com APP_ENV=testing e usam o banco DB_TEST_DATABASE
 * (padrão: gnesting_test), que é APAGADO e recriado a cada execução.
 */

use Dotenv\Dotenv;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$env = is_file($basePath . '/.env') ? Dotenv::parse((string) file_get_contents($basePath . '/.env')) : [];
$testDatabase = $env['DB_TEST_DATABASE'] ?? 'gnesting_test';

if ($testDatabase === ($env['DB_DATABASE'] ?? null)) {
    fwrite(STDERR, "DB_TEST_DATABASE não pode ser o mesmo banco de DB_DATABASE.\n");
    exit(1);
}

// Valores definidos antes do Dotenv não são sobrescritos por ele (modo imutável).
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_URL' => 'http://localhost',
    'DB_DATABASE' => $testDatabase,
] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
