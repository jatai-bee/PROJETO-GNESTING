<?php

declare(strict_types=1);

return [
    // log (desenvolvimento: storage/logs/mail-*.log) | mail (função mail() da hospedagem) | smtp
    'driver' => env('MAIL_DRIVER', 'log'),
    'from_address' => env('MAIL_FROM_ADDRESS', 'contato@gnesting.com.br'),
    'from_name' => env('MAIL_FROM_NAME', 'G-Nesting'),

    'smtp' => [
        'host' => (string) env('MAIL_HOST', ''),
        'port' => (int) env('MAIL_PORT', 587),
        // tls (STARTTLS, 587) | ssl (465) | none (somente servidor local de testes)
        'encryption' => (string) env('MAIL_ENCRYPTION', 'tls'),
        'username' => (string) env('MAIL_USERNAME', ''),
        'password' => (string) env('MAIL_PASSWORD', ''),
    ],
];
