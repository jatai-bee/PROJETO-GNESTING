<?php

declare(strict_types=1);

namespace GNesting\Services\Shipping;

use GNesting\Core\Config;
use GNesting\Helpers\ZipCode;

/**
 * Frete por tabela (config/shipping.php): faixa = mesma UF da origem ("local") ou região
 * de destino; preço = base até 1 kg + adicional por kg (arredondado para cima).
 */
final class TableShippingCalculator implements ShippingCalculator
{
    public function __construct(private readonly Config $config)
    {
    }

    public function quote(string $zip, int $weightGrams, int $subtotalCents): array
    {
        $state = ZipCode::state($zip);
        if ($state === null) {
            return [];
        }
        $band = $state === $this->config->get('shipping.origin_state') ? 'local' : ZipCode::region($state);
        $rates = $this->config->get("shipping.table.{$band}", []);

        $extraKg = max(0, (int) ceil(($weightGrams - 1000) / 1000));
        $freeFrom = $this->config->get('shipping.free_shipping_min_cents');
        $freeService = $this->config->get('shipping.free_shipping_service');

        $options = [];
        foreach ($rates as $code => [$base, $perKg, $days]) {
            $service = $this->config->get("shipping.services.{$code}");
            $price = $base + $extraKg * $perKg;
            if ($freeFrom !== null && $subtotalCents >= (int) $freeFrom && $code === $freeService) {
                $price = 0;
            }
            $options[] = new ShippingOption($code, (string) $service['carrier'], (string) $service['label'], $price, (int) $days);
        }

        if ($this->config->get('shipping.pickup.enabled')) {
            $options[] = new ShippingOption('retirada', 'Retirada', (string) $this->config->get('shipping.pickup.label'), 0, (int) $this->config->get('shipping.pickup.days', 0));
        }

        usort($options, static fn (ShippingOption $a, ShippingOption $b): int => $a->priceCents <=> $b->priceCents);

        return $options;
    }
}
