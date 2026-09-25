<?php

declare(strict_types=1);

/*
 * Restaura um backup de storage/backups. APAGA os dados atuais do banco (e, com --arquivos,
 * os uploads e arquivos de produção) e coloca os do backup no lugar. docs/17 §7.
 *
 *   php bin/restore.php 2026-09-25_060000 --confirmar [--arquivos]
 *
 * Coloque o site em manutenção antes (php bin/maintenance.php on).
 */

use GNesting\Core\Bootstrap;
use GNesting\Services\Operations\BackupService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$name = $argv[1] ?? '';
if ($name === '' || str_starts_with($name, '--')) {
    fwrite(STDERR, "Uso: php bin/restore.php NOME_DO_BACKUP --confirmar [--arquivos]\nBackups: php bin/backup.php --listar\n");
    exit(1);
}
if (!in_array('--confirmar', $argv, true)) {
    fwrite(STDERR, "Isto substitui TODO o banco atual pelo backup {$name}. Repita com --confirmar.\n");
    exit(1);
}

try {
    Bootstrap::createContainer($basePath)->get(BackupService::class)->restore(
        $name,
        in_array('--arquivos', $argv, true),
        static function (string $line): void {
            fwrite(STDOUT, $line . PHP_EOL);
        },
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Restauração concluída. Confira o site e desligue a manutenção (php bin/maintenance.php off).\n");
