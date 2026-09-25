<?php

declare(strict_types=1);

return [
    'session' => [
        'name' => env('SESSION_NAME', 'gn_session'),
        'lifetime_minutes' => (int) env('SESSION_LIFETIME', 120),
        'admin_absolute_hours' => (int) env('SESSION_ADMIN_ABSOLUTE_HOURS', 12),
        'secure_cookie' => (bool) env('SESSION_SECURE_COOKIE', false),
    ],

    // Rotas isentas de CSRF (protegidas por assinatura própria)
    'csrf_except' => [
        '/webhooks/',
    ],

    // Limites de tentativas: [máximo, janela em segundos]
    'rate_limits' => [
        'login' => [5, 900],          // por e-mail + IP
        'login_ip' => [20, 900],      // por IP (evita tentativa em massa de e-mails)
        'register' => [5, 3600],      // por IP
        'search' => [60, 60],         // buscas por IP por minuto
        'shipping' => [60, 60],       // cotações de frete por IP por minuto
    ],

    'password' => [
        'min_length' => 8,
        'max_length' => 128,
    ],
];
