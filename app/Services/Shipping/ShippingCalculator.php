<?php

declare(strict_types=1);

namespace GNesting\Services\Shipping;

/**
 * Cotação de frete. Implementação inicial: tabela (TableShippingCalculator);
 * uma API de transportadora entra como outra implementação, sem mudar o checkout.
 */
interface ShippingCalculator
{
    /**
     * @param string $zip           CEP de destino (8 dígitos)
     * @param int    $weightGrams   peso total embalado
     * @param int    $subtotalCents subtotal dos itens (frete grátis por valor)
     * @return list<ShippingOption> vazio = CEP não atendido
     */
    public function quote(string $zip, int $weightGrams, int $subtotalCents): array;
}
