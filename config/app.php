<?php

declare(strict_types=1);

$url = rtrim((string) env('APP_URL', 'http://localhost'), '/');

return [
    'name' => env('APP_NAME', 'G-Nesting'),
    'tagline' => 'Objetos que transformam espaços.',
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => $url,
    // Caminho da aplicação quando instalada em subpasta (ex.: http://localhost/gnesting → "/gnesting")
    'base_path' => rtrim((string) parse_url($url, PHP_URL_PATH), '/'),
    'timezone' => env('APP_TIMEZONE', 'America/Sao_Paulo'),
    'key' => env('APP_KEY', ''),
    'locale' => 'pt-BR',
];
