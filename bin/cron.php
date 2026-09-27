<?php

declare(strict_types=1);

/*
 * Tarefas periódicas. No cPanel (Cron Jobs), a cada 15 minutos:
 *   php /home/USUARIO/public_html/bin/cron.php
 *
 * As tarefas estão em app/Services/Operations/CronRunner.php (limpezas, pedidos não pagos,
 * backup diário). Silencioso quando tudo dá certo (o cPanel manda por e-mail qualquer saída do cron);
 * falhas saem no erro padrão, com código 1. Use -v para ver todas as tarefas.
 */

use GNesting\Core\Bootstrap;
use GNesting\Services\Operations\CronRunner;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

try {
    $results = Bootstrap::createContainer($basePath)->get(CronRunner::class)->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$verbose = in_array('-v', $argv, true);
$failed = false;
foreach ($results as $task => $result) {
    $failed = $failed || !$result['ok'];
    if (!$result['ok'] || $verbose) {
        fwrite($result['ok'] ? STDOUT : STDERR, sprintf("%s %s: %s\n", $result['ok'] ? 'ok ' : 'ERRO', $task, $result['detail']));
    }
}
exit($failed ? 1 : 0);
