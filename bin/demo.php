<?php

declare(strict_types=1);

/*
 * Dados de demonstração (docs/19):
 *   php bin/demo.php instalar   categorias, 34 produtos com fotos, clientes e pedidos em todas as etapas
 *   php bin/demo.php remover    apaga exatamente o que a demonstração criou
 * Sem Terminal: opção no instalador e botão em Painel → Sistema.
 */

use GNesting\Core\Bootstrap;
use GNesting\Services\Demo\DemoDataService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$demo = Bootstrap::createContainer($basePath)->get(DemoDataService::class);
$action = $argv[1] ?? '';

try {
    $result = match ($action) {
        'instalar' => $demo->install(static function (string $line): void {
            fwrite(STDOUT, $line . PHP_EOL);
        }),
        'remover' => $demo->remove(),
        default => throw new InvalidArgumentException('Uso: php bin/demo.php instalar|remover'),
    };
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($result as $label => $count) {
    fwrite(STDOUT, sprintf("  %-16s %d\n", $label, $count));
}
fwrite(STDOUT, $action === 'instalar' ? "Dados de demonstração instalados.\n" : "Dados de demonstração removidos.\n");
