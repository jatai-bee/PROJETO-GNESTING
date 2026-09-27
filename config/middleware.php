<?php

declare(strict_types=1);

use GNesting\Middleware\LoadCart;
use GNesting\Middleware\RedirectIfAdmin;
use GNesting\Middleware\RedirectIfCustomer;
use GNesting\Middleware\RequireAdmin;
use GNesting\Middleware\RequireAdminRole;
use GNesting\Middleware\RequireCustomer;
use GNesting\Middleware\StartSession;
use GNesting\Middleware\VerifyCsrfToken;

return [
    // Executados em toda requisição que encontrou rota, nesta ordem
    'global' => ['session', 'csrf', 'cart'],

    // Rotas sem sessão, CSRF nem carrinho (robôs e serviços externos não criam sessões à toa).
    // Caminho exato, ou prefixo quando termina em "/".
    'stateless' => ['/saude', '/sitemap.xml', '/robots.txt', '/webhooks/', '/sw.js', '/manifest.webmanifest', '/admin/manifest.webmanifest', '/offline'],

    // Apelidos usados nos arquivos de rotas. Parâmetros: 'role:manager,production'
    'aliases' => [
        'session' => StartSession::class,
        'csrf' => VerifyCsrfToken::class,
        'cart' => LoadCart::class,
        'auth' => RequireCustomer::class,
        'guest' => RedirectIfCustomer::class,
        'admin' => RequireAdmin::class,
        'admin.guest' => RedirectIfAdmin::class,
        'role' => RequireAdminRole::class,
    ],
];
