<?php

declare(strict_types=1);

/*
 * Tarefas periódicas. No cPanel, agende a cada 15 minutos:
 *   php /home/USUARIO/gnesting/bin/cron.php
 *
 * Novas tarefas entram aqui conforme as etapas (expirar pedidos, sitemap...).
 */

use GNesting\Core\Bootstrap;
use GNesting\Core\Logger;
use GNesting\Repositories\CartRepository;
use GNesting\Repositories\RateLimitRepository;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

try {
    $container = Bootstrap::createContainer($basePath);
    $removed = $container->get(RateLimitRepository::class)->purgeExpired();
    $carts = $container->get(CartRepository::class)->purgeExpired();
    $container->get(Logger::class)->info('cron: concluído', [
        'rate_limits_removidos' => $removed,
        'carrinhos_expirados_removidos' => $carts,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
