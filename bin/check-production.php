<?php

declare(strict_types=1);

/*
 * Lista de verificação de produção (docs/17 §4). Rode no servidor, depois de configurar o .env:
 *   php bin/check-production.php
 * Código de saída 1 se houver algum "erro".
 */

use GNesting\Core\Bootstrap;
use GNesting\Services\Operations\ProductionCheck;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$checks = Bootstrap::createContainer($basePath)->get(ProductionCheck::class)->run();
foreach ($checks as $check) {
    $mark = $check['ok'] ? '[ ok ]' : ($check['level'] === ProductionCheck::ERROR ? '[ERRO]' : '[aviso]');
    // mb_strlen: rótulos com acento (sprintf alinharia por bytes)
    fwrite(STDOUT, str_pad($mark, 8) . $check['label'] . str_repeat(' ', max(1, 34 - mb_strlen($check['label']))) . $check['detail'] . PHP_EOL);
}

$errors = count(array_filter($checks, fn ($c) => !$c['ok'] && $c['level'] === ProductionCheck::ERROR));
$warnings = count(array_filter($checks, fn ($c) => !$c['ok'] && $c['level'] === ProductionCheck::WARNING));
fwrite(STDOUT, PHP_EOL . ($errors === 0 ? 'Pronto para produção' : "{$errors} erro(s): corrija antes de publicar")
    . ($warnings > 0 ? " · {$warnings} aviso(s)" : '') . '.' . PHP_EOL);
exit($errors === 0 ? 0 : 1);
