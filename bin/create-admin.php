<?php

declare(strict_types=1);

/*
 * Cria um administrador.
 *
 *   php bin/create-admin.php --email=voce@gnesting.com.br --name="Seu Nome" [--role=owner]
 *
 * A senha é pedida no terminal (não passe senha como argumento: fica no histórico).
 * Deixe em branco para gerar uma senha forte, exibida uma única vez.
 * Papéis: owner (proprietário), manager (gestor), production (produção), support (atendimento).
 */

use GNesting\Core\Bootstrap;
use GNesting\Core\ValidationException;
use GNesting\Enums\AdminRole;
use GNesting\Services\Auth\AuthService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$options = getopt('', ['email:', 'name:', 'role::']);
$email = trim((string) ($options['email'] ?? ''));
$name = trim((string) ($options['name'] ?? ''));
$role = AdminRole::tryFrom((string) ($options['role'] ?? 'owner'));

if ($email === '' || $name === '' || $role === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Uso: php bin/create-admin.php --email=EMAIL --name=\"NOME\" [--role=owner|manager|production|support]\n");
    exit(1);
}

fwrite(STDOUT, 'Senha (mínimo 12 caracteres; Enter para gerar uma): ');
$password = rtrim((string) fgets(STDIN), "\r\n");
$generated = false;
if ($password === '') {
    $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    $generated = true;
} elseif (mb_strlen($password) < 12) {
    fwrite(STDERR, "Senha de administrador deve ter pelo menos 12 caracteres.\n");
    exit(1);
}

try {
    $container = Bootstrap::createContainer($basePath);
    $adminId = $container->get(AuthService::class)->createAdmin($name, $email, $password, $role);
} catch (ValidationException $e) {
    fwrite(STDERR, implode(PHP_EOL, $e->errors()) . PHP_EOL);
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Administrador #{$adminId} criado: {$email} ({$role->label()}).\n");
if ($generated) {
    fwrite(STDOUT, "Senha gerada (guarde agora, não será exibida novamente): {$password}\n");
}
