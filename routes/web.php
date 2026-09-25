<?php

declare(strict_types=1);

/*
 * Loja e área do cliente. Mapa completo em docs/04-rotas.md.
 * Middleware globais (session, csrf, cart) são aplicados pelo Kernel.
 */

use GNesting\Controllers\Store\AccountController;
use GNesting\Controllers\Store\AuthController;
use GNesting\Controllers\Store\CartController;
use GNesting\Controllers\Store\CatalogController;
use GNesting\Controllers\Store\HomeController;
use GNesting\Controllers\Store\PageController;
use GNesting\Controllers\Store\ProductController;
use GNesting\Core\Router;

return static function (Router $r): void {
    $r->get('/', [HomeController::class, 'index']);

    // Catálogo
    $r->get('/produtos', [CatalogController::class, 'index']);
    $r->get('/categoria/{slug:[a-z0-9-]+}', [CatalogController::class, 'category']);
    $r->get('/busca', [CatalogController::class, 'search']);
    $r->get('/produto/{slug:[a-z0-9-]+}', [ProductController::class, 'show']);

    // Carrinho
    $r->get('/carrinho', [CartController::class, 'show']);
    $r->post('/carrinho/itens', [CartController::class, 'add']);
    $r->post('/carrinho/itens/{id:\d+}', [CartController::class, 'update']);
    $r->post('/carrinho/itens/{id:\d+}/remover', [CartController::class, 'remove']);

    // Institucionais
    foreach (array_keys(PageController::PAGES) as $page) {
        $r->get('/' . $page, [PageController::class, 'show']);
    }

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
