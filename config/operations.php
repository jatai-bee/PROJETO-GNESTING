<?php

declare(strict_types=1);

/*
 * Operação em produção: HTTPS, proxies, monitoramento, alertas, backups e limpeza.
 * Guia: docs/17-deploy-e-operacao.md
 */

return [
    // Em produção, redireciona http → https e www/outro host → o host de APP_URL.
    // Padrão: ligado quando APP_ENV=production e APP_URL começa com https://
    'force_https' => env('APP_FORCE_HTTPS'),

    // IPs ou faixas CIDR dos proxies na frente do site (Cloudflare, balanceador), separados por vírgula.
    // Só deles os cabeçalhos X-Forwarded-* são aceitos. Vazio = conexão direta.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    // /saude?token=… mostra os detalhes (sem token: só "ok"/"falha"). Vazio = sem detalhes.
    'health_token' => (string) env('HEALTH_TOKEN', ''),

    // Alertas por e-mail (erro 500, falha do cron ou do backup). Vazio = sem alertas.
    'alert_email' => (string) env('ALERT_EMAIL', ''),
    'alert_throttle_minutes' => 60,   // o mesmo alerta no máximo 1× por hora
    'alert_max_per_day' => 20,

    // O cron roda a cada 15 min; sem batimento há mais que isto, /saude acusa
    'cron_stale_minutes' => 45,

    'log_retention_days' => (int) env('LOG_RETENTION_DAYS', 30),

    'backup' => [
        'hour' => (int) env('BACKUP_HOUR', 3),              // primeiro cron depois desta hora (horário da loja)
        'keep_daily' => (int) env('BACKUP_KEEP_DAILY', 7),  // últimos N backups
        'keep_monthly' => (int) env('BACKUP_KEEP_MONTHLY', 3), // + o primeiro de cada um dos últimos N meses
        'include_files' => (bool) env('BACKUP_INCLUDE_FILES', true), // uploads + arquivos de produção
        'stale_hours' => 30,                                 // /saude acusa backup mais velho que isto
    ],
];
