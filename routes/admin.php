<?php

declare(strict_types=1);

/*
 * Painel administrativo (/admin). Papéis por rota: docs/04-rotas.md §3.
 * O proprietário (owner) passa por qualquer restrição de papel.
 *
 * Convenção: formulários usam a MESMA URL no GET (exibir) e no POST (salvar),
 * para que erros de validação voltem ao formulário preenchido.
 */

use GNesting\Controllers\Admin\AdminUserController;
use GNesting\Controllers\Admin\AuditLogController;
use GNesting\Controllers\Admin\AuthController;
use GNesting\Controllers\Admin\CategoryController;
use GNesting\Controllers\Admin\DashboardController;
use GNesting\Controllers\Admin\ProductController;
use GNesting\Controllers\Admin\ProductImageController;
use GNesting\Core\Router;

return static function (Router $r): void {
    $r->group(['prefix' => '/admin'], static function (Router $r): void {
        $r->group(['middleware' => ['admin.guest']], static function (Router $r): void {
            $r->get('/login', [AuthController::class, 'showLogin']);
            $r->post('/login', [AuthController::class, 'login']);
        });

        $r->group(['middleware' => ['admin']], static function (Router $r): void {
            $r->get('/', [DashboardController::class, 'index']);
            $r->post('/sair', [AuthController::class, 'logout']);

            // Catálogo: gestor
            $r->group(['middleware' => ['role:manager']], static function (Router $r): void {
                $r->get('/categorias', [CategoryController::class, 'index']);
                $r->get('/categorias/novo', [CategoryController::class, 'create']);
                $r->post('/categorias/novo', [CategoryController::class, 'store']);
                $r->get('/categorias/{id:\d+}/editar', [CategoryController::class, 'edit']);
                $r->post('/categorias/{id:\d+}/editar', [CategoryController::class, 'update']);
                $r->post('/categorias/{id:\d+}/excluir', [CategoryController::class, 'destroy']);

                $r->get('/produtos', [ProductController::class, 'index']);
                $r->get('/produtos/novo', [ProductController::class, 'create']);
                $r->post('/produtos/novo', [ProductController::class, 'store']);
                $r->get('/produtos/{id:\d+}/editar', [ProductController::class, 'edit']);
                $r->post('/produtos/{id:\d+}/editar', [ProductController::class, 'update']);
                $r->post('/produtos/{id:\d+}/status', [ProductController::class, 'status']);
                $r->post('/produtos/{id:\d+}/excluir', [ProductController::class, 'destroy']);

                $r->get('/produtos/{id:\d+}/imagens', [ProductImageController::class, 'index']);
                $r->post('/produtos/{id:\d+}/imagens', [ProductImageController::class, 'upload']);
                $r->post('/produtos/{id:\d+}/imagens/{imageId:\d+}/texto', [ProductImageController::class, 'alt']);
                $r->post('/produtos/{id:\d+}/imagens/{imageId:\d+}/capa', [ProductImageController::class, 'cover']);
                $r->post('/produtos/{id:\d+}/imagens/{imageId:\d+}/mover', [ProductImageController::class, 'move']);
                $r->post('/produtos/{id:\d+}/imagens/{imageId:\d+}/excluir', [ProductImageController::class, 'destroy']);
            });

            // Equipe e auditoria: somente proprietário
            $r->group(['middleware' => ['role:owner']], static function (Router $r): void {
                $r->get('/usuarios', [AdminUserController::class, 'index']);
                $r->get('/usuarios/novo', [AdminUserController::class, 'create']);
                $r->post('/usuarios/novo', [AdminUserController::class, 'store']);
                $r->get('/usuarios/{id:\d+}/editar', [AdminUserController::class, 'edit']);
                $r->post('/usuarios/{id:\d+}/editar', [AdminUserController::class, 'update']);
                $r->post('/usuarios/{id:\d+}/senha', [AdminUserController::class, 'password']);

                $r->get('/logs', [AuditLogController::class, 'index']);
            });
        });
    });
};
