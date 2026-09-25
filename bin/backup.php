<?php

declare(strict_types=1);

/*
 * Backup manual (o cron já faz um por dia). docs/17 §6.
 *   php bin/backup.php              banco + arquivos
 *   php bin/backup.php --sem-arquivos
 *   php bin/backup.php --listar
 */

use GNesting\Core\Bootstrap;
use GNesting\Services\Operations\BackupService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$backups = Bootstrap::createContainer($basePath)->get(BackupService::class);

if (in_array('--listar', $argv, true)) {
    foreach ($backups->list() as $backup) {
        fwrite(STDOUT, sprintf("%s  %8.1f MB  %s linhas%s\n", $backup['name'], $backup['size_bytes'] / 1048576, $backup['rows'],
            isset($backup['files']['files.tar.gz']) ? '  + arquivos' : ''));
    }
    exit(0);
}

try {
    $manifest = $backups->create(!in_array('--sem-arquivos', $argv, true));
    $removed = $backups->prune();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf("Backup %s criado em storage/backups/%s (%.1f MB, %d linhas, %.1f s).\n",
    $manifest['name'], $manifest['name'], array_sum(array_column($manifest['files'], 'bytes')) / 1048576, $manifest['rows'], $manifest['duration_seconds']));
if ($removed !== []) {
    fwrite(STDOUT, 'Removidos pela retenção: ' . implode(', ', $removed) . PHP_EOL);
}
