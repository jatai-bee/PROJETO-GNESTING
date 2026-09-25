<?php

declare(strict_types=1);

/*
 * Modo manutenção (docs/17 §8):
 *   php bin/maintenance.php on ["mensagem para os clientes"]   liga e mostra o link de passagem
 *   php bin/maintenance.php off
 *   php bin/maintenance.php status
 */

use GNesting\Core\Bootstrap;
use GNesting\Core\Config;
use GNesting\Core\Maintenance;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$container = Bootstrap::createContainer($basePath);
$maintenance = $container->get(Maintenance::class);
$url = rtrim((string) $container->get(Config::class)->get('app.url'), '/');

switch ($argv[1] ?? 'status') {
    case 'on':
        $secret = $maintenance->enable((string) ($argv[2] ?? ''));
        fwrite(STDOUT, "Manutenção LIGADA: a loja responde 503 (\"Voltamos já\").\n"
            . "Para você entrar mesmo assim, abra: {$url}/?" . Maintenance::BYPASS_QUERY . "={$secret}\n");
        break;
    case 'off':
        $maintenance->disable();
        fwrite(STDOUT, "Manutenção desligada.\n");
        break;
    default:
        $status = $maintenance->status();
        fwrite(STDOUT, $status === null ? "Manutenção desligada.\n" : "Manutenção LIGADA desde {$status['since']} UTC.\n");
}
