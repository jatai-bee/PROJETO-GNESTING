<?php

declare(strict_types=1);

/*
 * G-Nesting — tarefas periódicas por URL, para hospedagem sem Cron Jobs (docs/17 §9):
 *   https://SUA-LOJA/cron.php?token=CRON_TOKEN   (a cada 15 min, por um serviço de ping)
 * Prefira o Cron Jobs do cPanel quando existir: é um formulário, também não exige Terminal.
 */

use GNesting\Core\Bootstrap;
use GNesting\Core\ErrorHandler;
use GNesting\Services\Operations\WebCron;

header('Content-Type: text/plain; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$basePath = dirname(__DIR__);
if (!is_file($basePath . '/vendor/autoload.php') || !is_file($basePath . '/.env')) {
    http_response_code(503);
    exit("indisponível\n");
}
require $basePath . '/vendor/autoload.php';

try {
    $container = Bootstrap::createContainer($basePath);
} catch (Throwable $e) {
    error_log('[G-Nesting] cron.php: falha ao iniciar: ' . $e->getMessage());
    http_response_code(503);
    exit("indisponível\n");
}
$container->get(ErrorHandler::class)->register();

// O backup pode passar do limite padrão de uma requisição web; e o serviço de ping pode desistir antes do fim
@set_time_limit(300);
ignore_user_abort(true);

$token = $_GET['token'] ?? '';
[$status, $body] = $container->get(WebCron::class)->handle(is_string($token) ? $token : '');
http_response_code($status);
echo $body;
