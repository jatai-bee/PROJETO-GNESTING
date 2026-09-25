<?php

declare(strict_types=1);

return [
    // mercadopago | simulado (somente fora de produção)
    'provider' => env('PAYMENT_PROVIDER', 'simulado') ?: 'simulado',

    'mercadopago' => [
        // Credenciais do painel do Mercado Pago (Suas integrações → Credenciais).
        // Teste: use as credenciais de teste; produção: as de produção.
        'access_token' => (string) env('MERCADOPAGO_ACCESS_TOKEN', ''),
        // Webhooks → "Assinatura secreta"
        'webhook_secret' => (string) env('MERCADOPAGO_WEBHOOK_SECRET', ''),
        'max_installments' => (int) env('MERCADOPAGO_MAX_INSTALLMENTS', 12),
    ],

    // Horas para pagar; depois disso o pedido é cancelado pelo cron e o estoque volta
    'expiry_hours' => (int) env('PAYMENT_EXPIRY_HOURS', 48),

    // Pedidos por IP por hora (evita reservas de estoque em massa)
    'max_orders_per_hour' => 10,
];
