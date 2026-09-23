<?php

declare(strict_types=1);

/*
 * Gera APP_KEY no arquivo .env (usada para HMAC interno).
 *
 *   php bin/generate-key.php          só gera se APP_KEY estiver vazia
 *   php bin/generate-key.php --force  substitui a chave existente
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$envFile = dirname(__DIR__) . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, ".env não encontrado. Copie .env.example para .env primeiro.\n");
    exit(1);
}

$content = (string) file_get_contents($envFile);
$force = in_array('--force', $argv, true);

if (preg_match('/^APP_KEY=(.*)$/m', $content, $match) && trim($match[1]) !== '' && !$force) {
    fwrite(STDOUT, "APP_KEY já definida. Use --force para substituir.\n");
    exit(0);
}

$key = 'base64:' . base64_encode(random_bytes(32));
$content = preg_match('/^APP_KEY=.*$/m', $content)
    ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $content)
    : rtrim($content) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;

file_put_contents($envFile, $content, LOCK_EX);
fwrite(STDOUT, "APP_KEY gerada.\n");
