<?php

declare(strict_types=1);

/*
 * G-Nesting — front controller. Único ponto de entrada da aplicação web.
 */

use GNesting\Core\Bootstrap;
use GNesting\Core\Config;
use GNesting\Core\ErrorHandler;
use GNesting\Core\Kernel;
use GNesting\Core\Request;

// Servidor embutido do PHP (composer serve): entrega arquivos estáticos (e o instalador/cron) diretamente.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)
        && (!str_ends_with($file, '.php') || in_array(basename($file), ['instalar.php', 'cron.php'], true))) {
        return false;
    }
}

$basePath = dirname(__DIR__);

if (!is_file($basePath . '/vendor/autoload.php')) {
    http_response_code(503);
    echo 'Pacote incompleto: falta a pasta vendor/. Envie o .zip gerado por bin/build-release.php.';
    exit;
}

// Ainda não instalada: leva ao assistente (public/instalar.php), que grava o .env
if (!is_file($basePath . '/.env')) {
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    header('Location: ' . preg_replace('#/public$#', '', $dir) . '/instalar.php', true, 302);
    exit;
}

require $basePath . '/vendor/autoload.php';

try {
    $container = Bootstrap::createContainer($basePath);
} catch (Throwable $e) {
    error_log('[G-Nesting] Falha ao iniciar: ' . $e->getMessage());
    http_response_code(503);
    echo 'Serviço temporariamente indisponível.';
    exit;
}

$container->get(ErrorHandler::class)->register();

$request = Request::fromGlobals((string) $container->get(Config::class)->get('app.base_path', ''));
$container->get(Kernel::class)->handle($request)->send();
