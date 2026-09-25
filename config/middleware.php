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
