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
        'coupon' => [10, 600],        // tentativas de cupom por IP a cada 10 min
        'password_reset' => [3, 3600],     // pedidos de redefinição por e-mail
        'password_reset_ip' => [10, 3600], // pedidos de redefinição por IP
    ],

    // LGPD: pedidos mais antigos que isto têm os dados pessoais apagados ao anonimizar um cliente
    'privacy' => [
        'order_retention_years' => (int) env('ORDER_RETENTION_YEARS', 5),
    ],

    'password' => [
        'min_length' => 8,
        'max_length' => 128,
    ],
];
