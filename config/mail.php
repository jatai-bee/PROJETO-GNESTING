<?php

declare(strict_types=1);

return [
    // log (desenvolvimento: storage/logs/mail-*.log) | mail (função mail() da hospedagem)
    'driver' => env('MAIL_DRIVER', 'log'),
    'from_address' => env('MAIL_FROM_ADDRESS', 'contato@gnesting.com.br'),
    'from_name' => env('MAIL_FROM_NAME', 'G-Nesting'),
];
