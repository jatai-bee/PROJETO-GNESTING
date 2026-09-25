<?php

declare(strict_types=1);

/*
 * Frete por tabela (etapa 7). Troque por API de transportadora mudando 'provider'
 * e implementando ShippingCalculator (docs/12-checkout.md §3).
 *
 * VALORES DE EXEMPLO: revise preços e prazos com a sua realidade de envio.
 * Preço = base (até 1 kg) + adicional por kg acima de 1 kg (arredondado para cima).
 * Prazo = dias úteis de transporte (o prazo de produção é somado à parte).
 */

return [
    'provider' => env('SHIPPING_PROVIDER', 'table') ?: 'table',

    // UF de onde os pedidos saem: a própria UF tem a faixa "local"
    'origin_state' => strtoupper((string) env('SHIPPING_ORIGIN_STATE', 'BA')),

    // Peso usado quando a variante não tem peso de embalagem nem peso do produto
    'default_package_weight_g' => 1000,
    // Acréscimo de embalagem quando só existe o peso do produto
    'packaging_weight_g' => 200,

    // Frete grátis a partir deste subtotal (centavos). null = desativado
    'free_shipping_min_cents' => null,
    'free_shipping_service' => 'economico',

    'services' => [
        'economico' => ['carrier' => 'Correios', 'label' => 'Econômico (PAC)'],
        'expresso' => ['carrier' => 'Correios', 'label' => 'Expresso (SEDEX)'],
    ],

    // faixa => serviço => [base até 1 kg, por kg adicional, prazo em dias úteis]
    'table' => [
        'local' => ['economico' => [1990, 400, 4], 'expresso' => [2990, 700, 2]],
        'NE' => ['economico' => [2490, 500, 7], 'expresso' => [3990, 900, 3]],
        'SE' => ['economico' => [2990, 600, 8], 'expresso' => [4990, 1100, 4]],
        'CO' => ['economico' => [3290, 650, 9], 'expresso' => [5490, 1200, 5]],
        'S' => ['economico' => [3490, 700, 10], 'expresso' => [5990, 1300, 5]],
        'N' => ['economico' => [3990, 800, 12], 'expresso' => [6990, 1500, 6]],
    ],

    // Retirada no ateliê (sem custo). Deixe 'enabled' => false se não houver.
    'pickup' => [
        'enabled' => (bool) env('SHIPPING_PICKUP', false),
        'label' => 'Retirada no ateliê',
        'days' => 0,
    ],
];
