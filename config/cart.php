<?php

declare(strict_types=1);

return [
    'cookie' => 'gn_cart',
    'lifetime_days' => 30,        // validade do cookie e do carrinho (renovada a cada alteração)
    'max_quantity' => 99,         // por linha (limite também no banco: chk_cart_items_qty)
    'max_lines' => 30,            // linhas distintas por carrinho
];
