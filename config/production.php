<?php

declare(strict_types=1);

return [
    // Minutos de OPERADOR por dia útil (somando as pessoas na produção). Etapas passivas
    // (secagem) não contam. Ex.: 1 pessoa, 7 h produtivas = 420.
    'daily_capacity_minutes' => (int) env('PRODUCTION_DAILY_MINUTES', 420),
];
