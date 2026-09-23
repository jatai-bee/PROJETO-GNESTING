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

// Servidor embutido do PHP (composer serve): entrega arquivos estáticos diretamente.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

$basePath = dirname(__DIR__);

if (!is_file($basePath . '/vendor/autoload.php')) {
    http_response_code(503);
    echo 'Dependências não instaladas. Execute "composer install".';
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
