<?php

declare(strict_types=1);

/*
 * Verifica a sintaxe (php -l) de todos os arquivos PHP do projeto, inclusive as views.
 * Uso: composer lint   (ou php bin/lint.php)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$basePath = dirname(__DIR__);
$failed = 0;
$count = 0;

foreach (['app', 'bin', 'config', 'database', 'public', 'routes', 'tests'] as $dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $count++;
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg((string) $file) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            $failed++;
            fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        }
    }
}

fwrite($failed ? STDERR : STDOUT, sprintf('%d arquivo(s) verificado(s), %d com erro.%s', $count, $failed, PHP_EOL));
exit($failed ? 1 : 0);
