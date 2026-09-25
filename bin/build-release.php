<?php

declare(strict_types=1);

/*
 * Gera o pacote de produção (docs/17 §2), a partir do ÚLTIMO COMMIT (alterações não commitadas ficam de fora):
 *
 *   php bin/build-release.php
 *   → build/gnesting-AAAAMMDD-HHMM-<commit>.zip
 *
 * O pacote tem vendor/ só com as dependências de produção (composer install --no-dev) e não tem testes,
 * docs nem CI (.gitattributes export-ignore). Nunca contém .env, logs, sessões, uploads ou backups.
 * Composer: usa COMPOSER_PHAR (caminho do composer.phar) ou o comando "composer" do PATH.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$fail = static function (string $message): never {
    fwrite(STDERR, 'ERRO: ' . $message . PHP_EOL);
    exit(1);
};
$run = static function (string $command, ?string $cwd = null) use ($fail): string {
    $descriptors = [0 => ['file', 'php://stdin', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        $fail("não foi possível executar: {$command}");
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    if (proc_close($process) !== 0) {
        $fail("{$command}\n{$err}{$out}");
    }

    return trim($out);
};

if (!class_exists(ZipArchive::class)) {
    $fail('a extensão zip do PHP é necessária para montar o pacote.');
}

$commit = $run('git rev-parse --short HEAD', $root);
$dirty = $run('git status --porcelain --untracked-files=no', $root);
$name = 'gnesting-' . date('Ymd-Hi') . '-' . $commit;
$build = $root . '/build';
$work = $build . '/' . $name;
@mkdir($build, 0777, true);

fwrite(STDOUT, "Pacote {$name} (commit {$commit})" . ($dirty !== '' ? ' — atenção: há alterações NÃO commitadas, que ficam de fora' : '') . PHP_EOL);

// 1. Arquivos do commit (sem o que está marcado export-ignore)
$archive = $build . '/' . $name . '-src.zip';
$run('git archive --worktree-attributes --format=zip --output=' . escapeshellarg($archive) . ' HEAD', $root);
$zip = new ZipArchive();
$zip->open($archive) === true || $fail('não foi possível abrir o arquivo do git archive.');
$zip->extractTo($work) || $fail('não foi possível extrair o código.');
$zip->close();
unlink($archive);

// 2. Dependências de produção
$phar = getenv('COMPOSER_PHAR') ?: '';
$composer = $phar !== '' ? escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phar) : 'composer';
fwrite(STDOUT, "composer install --no-dev…\n");
$run($composer . ' install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --no-scripts', $work);

// 3. Identificação da versão (aparece na tela Sistema)
file_put_contents($work . '/RELEASE', "commit={$commit}\nbuilt_at_utc=" . gmdate('Y-m-d H:i:s') . "\n");

// 4. Conferência: nada que não deveria ir
foreach (['.env', 'tests', 'docs', '.github', 'vendor/phpunit', 'vendor/phpstan'] as $forbidden) {
    if (file_exists($work . '/' . $forbidden)) {
        $fail("o pacote não deveria conter {$forbidden}.");
    }
}

// 5. Zip final
$zipPath = $build . '/' . $name . '.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true || $fail("não foi possível criar {$zipPath}.");
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$count = 0;
foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr((string) $file, strlen($work) + 1));
    if ($file->isDir()) {
        $zip->addEmptyDir($relative);
    } else {
        $zip->addFile((string) $file, $relative);
        $count++;
    }
}
$zip->close();

// 6. Limpa a pasta de trabalho
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
    $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
}
rmdir($work);

fwrite(STDOUT, sprintf("Pronto: build/%s.zip (%d arquivos, %.1f MB)\n", $name, $count, filesize($zipPath) / 1048576));
