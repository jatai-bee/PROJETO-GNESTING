<?php

declare(strict_types=1);

/*
 * Loja e área do cliente. Mapa completo em docs/04-rotas.md.
 * Middleware globais (session, csrf) são aplicados pelo Kernel.
 */

use GNesting\Controllers\Store\AccountController;
use GNesting\Controllers\Store\AuthController;
use GNesting\Controllers\Store\HomeController;
use GNesting\Core\Router;

return static function (Router $r): void {
    $r->get('/', [HomeController::class, 'index']);

    $r->group(['middleware' => ['guest']], static function (Router $r): void {
        $r->get('/entrar', [AuthController::class, 'showLogin']);
        $r->post('/entrar', [AuthController::class, 'login']);
        $r->get('/cadastro', [AuthController::class, 'showRegister']);
        $r->post('/cadastro', [AuthController::class, 'register']);
    });

    $r->group(['middleware' => ['auth']], static function (Router $r): void {
        $r->post('/sair', [AuthController::class, 'logout']);
        $r->get('/conta', [AccountController::class, 'index']);
    });
};
