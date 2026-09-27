<?php

declare(strict_types=1);

/*
 * G-Nesting — assistente de instalação pelo navegador (hospedagem sem Terminal/SSH).
 *
 * Ponto de entrada próprio, separado do index.php: o index depende do .env, e é este
 * assistente que grava o .env. Depois de instalar, grava storage/installed.lock e passa
 * a recusar qualquer acesso (docs/17 §3).
 */

use Dotenv\Dotenv;
use GNesting\Core\ValidationException;
use GNesting\Install\InstallForm;
use GNesting\Install\Installer;
use GNesting\Install\RequirementsCheck;

$basePath = dirname(__DIR__);

header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

if (!is_file($basePath . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('<!doctype html><meta charset="utf-8"><title>Falta a pasta vendor</title><h1>Falta a pasta vendor/</h1>'
        . '<p>Envie o pacote completo da loja (o .zip gerado por bin/build-release.php já inclui a pasta vendor/).</p>');
}
require $basePath . '/vendor/autoload.php';

$installer = new Installer($basePath);
$step = 'form';
$errors = [];
$log = [];
$failure = null;
$cronUrl = null;

/** Endereço da loja deduzido da própria requisição (poupa digitação e erro). */
$guessedUrl = (static function (): string {
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/instalar.php'))), '/');
    $path = (string) preg_replace('#/public$#', '', $path); // .htaccess da raiz encaminhando para public/

    return ($https ? 'https://' : 'http://') . $host . $path;
})();

if ($installer->isInstalled()) {
    http_response_code(403);
    $step = 'installed';
    $requirements = [];
    $data = [];
    require $basePath . '/app/Views/install/page.php';
    exit;
}

$requirements = $installer->requirements()->all();
$blocked = RequirementsCheck::blocked($requirements);
$data = InstallForm::defaults($guessedUrl);

// CSRF por "double submit": o formulário precisa trazer o mesmo valor do cookie
$token = (string) ($_COOKIE['gn_instalar'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
    $token = bin2hex(random_bytes(16));
    setcookie('gn_instalar', $token, ['httponly' => true, 'samesite' => 'Strict', 'path' => '/',
        'secure' => str_starts_with($guessedUrl, 'https://')]);
}

if ($blocked) {
    $step = 'requirements';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $data = InstallForm::fromInput($_POST, $guessedUrl);
    if (!hash_equals($token, (string) ($_POST['_token'] ?? ''))) {
        $failure = 'A página ficou aberta tempo demais ou os cookies estão bloqueados. Confira os dados e envie de novo.';
    } else {
        $errors = InstallForm::validate($data);
        if ($errors === []) {
            $test = $installer->testDatabase([
                'host' => $data['db_host'], 'port' => (int) $data['db_port'], 'database' => $data['db_database'],
                'username' => $data['db_username'], 'password' => $data['db_password'],
            ]);
            if (!$test['ok']) {
                $errors['db_database'] = $test['message'];
            }
        }
        if ($errors === []) {
            @set_time_limit(300);
            try {
                $log = $installer->run($data);
                $step = 'done';
                $env = Dotenv::parse((string) file_get_contents($basePath . '/.env'));
                $cronUrl = rtrim($data['app_url'], '/') . '/cron.php?token=' . ($env['CRON_TOKEN'] ?? '');
            } catch (ValidationException $e) {
                $failure = implode(' ', $e->errors());
            } catch (Throwable $e) {
                error_log('[G-Nesting] Instalação falhou: ' . $e->getMessage());
                $failure = $e->getMessage();
            }
        }
    }
}

foreach (InstallForm::SECRETS as $secret) {
    $data[$secret] = ''; // senhas nunca voltam para a tela
}

require $basePath . '/app/Views/install/page.php';
