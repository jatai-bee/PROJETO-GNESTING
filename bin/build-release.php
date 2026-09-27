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
date_default_timezone_set('America/Sao_Paulo'); // nome do pacote e LEIA-ME no horário da loja (RELEASE fica em UTC)
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

// 3. Identificação da versão (aparece na tela Sistema) e instruções curtas para quem vai instalar
file_put_contents($work . '/RELEASE', "commit={$commit}\nbuilt_at_utc=" . gmdate('Y-m-d H:i:s') . "\n");
file_put_contents($work . '/LEIA-ME-INSTALACAO.txt', str_replace("\n", "\r\n", readme($commit)));

// 4. Conferência: nada que não deveria ir
foreach (['.env', 'tests', 'docs', '.github', 'gerar-pacote.cmd', 'vendor/phpunit', 'vendor/phpstan'] as $forbidden) {
    if (file_exists($work . '/' . $forbidden)) {
        $fail("o pacote não deveria conter {$forbidden}.");
    }
}
// Tudo em public/ fica acessível pela web: sobras de testes (qa-*.html, info.php…) não podem ir junto
$unexpected = array_diff(scandir($work . '/public') ?: [], ['.', '..', '.htaccess', 'index.php', 'instalar.php', 'cron.php', 'assets', 'uploads']);
if ($unexpected !== []) {
    $fail('arquivos inesperados em public/: ' . implode(', ', $unexpected) . '.');
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

/** Texto do LEIA-ME-INSTALACAO.txt (também copiado ao lado do .zip pelo gerar-pacote.cmd). */
function readme(string $commit): string
{
    $date = date('d/m/Y H:i');

    return <<<TXT
        G-NESTING - PACOTE DA LOJA PARA HOSPEDAGEM (cPanel)
        Versão: {$commit}, gerada em {$date}

        Este .zip é a loja completa, pronta para usar. Não precisa de Terminal nem de Composer no servidor.
        O guia passo a passo, com telas e soluções de problemas, é o "Guia de instalação e uso" (Word).

        RESUMO DA INSTALAÇÃO
        --------------------
        1. Banco: cPanel > Bancos de dados MySQL > crie o banco e um usuário e adicione o usuário ao banco
           com TODOS OS PRIVILÉGIOS. Anote nome do banco, usuário e senha (com o prefixo do cPanel).
        2. Pasta: cPanel > Gerenciador de Arquivos > abra public_html e apague os arquivos padrão da
           hospedagem (index.html, default.php...). Não apague cgi-bin nem .well-known.
        3. Envio: dentro de public_html > Carregar > envie este .zip > botão direito > Extract
           (destino /home/USUARIO/public_html) > apague o .zip.
           Dentro de public_html devem aparecer as pastas app, public, storage, vendor...
        4. HTTPS: cPanel > SSL/TLS Status > Run AutoSSL. Espere o cadeado aparecer no endereço da loja.
        5. Proteção: abra https://SEU-DOMINIO/app/ e https://SEU-DOMINIO/.env.example no navegador.
           Os dois devem responder "Forbidden" (bloqueado). Se mostrarem conteúdo, pare e peça ao
           suporte para ativar o mod_rewrite.
        6. Instalação: abra https://SEU-DOMINIO/instalar.php e preencha o assistente.
           Guarde o comando do Cron Jobs e o endereço do cron que aparecem no final.
        7. Depois (recomendado): cPanel > Domínios > Gerenciar > Raiz do documento = public_html/public.
           A loja continua igual, com uma camada extra de segurança.

        Depois: painel em https://SEU-DOMINIO/admin > Sistema (lista de verificação e backup).

        NUNCA envie a ninguém o arquivo .env que o assistente cria: ele guarda as senhas da loja.

        TXT;
}
