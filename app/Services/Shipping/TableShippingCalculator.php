<?php

declare(strict_types=1);

namespace GNesting\Services\Shipping;

use GNesting\Core\Config;
use GNesting\Helpers\ZipCode;

/**
 * Frete por tabela: faixa = mesma UF da origem ("local") ou região de destino;
 * preço = base até 1 kg + adicional por kg (arredondado para cima).
 * A tabela vem de ShippingSettings (importada pelo painel) ou, sem ele, de config/shipping.php.
 */
final class TableShippingCalculator implements ShippingCalculator
{
    public function __construct(
        private readonly Config $config,
        private readonly ?ShippingSettings $settings = null,
    ) {
    }

    public function quote(string $zip, int $weightGrams, int $subtotalCents): array
    {
        $state = ZipCode::state($zip);
        if ($state === null) {
            return [];
        }
        $shipping = $this->settings?->effective() ?? ShippingSettings::fromConfig($this->config);
        $band = $state === $shipping['origin_state'] ? 'local' : ZipCode::region($state);
        $rates = $shipping['table'][$band] ?? [];

        $extraKg = max(0, (int) ceil(($weightGrams - 1000) / 1000));
        $freeFrom = $shipping['free_shipping_min_cents'];

        $options = [];
        foreach ($rates as $code => [$base, $perKg, $days]) {
            $service = $this->config->get("shipping.services.{$code}");
            if (!is_array($service)) {
                continue; // serviço que não existe mais em config/shipping.php
            }
            $price = $base + $extraKg * $perKg;
            if ($freeFrom !== null && $subtotalCents >= $freeFrom && $code === $shipping['free_shipping_service']) {
                $price = 0;
            }
            $options[] = new ShippingOption($code, (string) $service['carrier'], (string) $service['label'], $price, (int) $days);
        }

        if ($shipping['pickup']['enabled']) {
            $options[] = new ShippingOption('retirada', 'Retirada', $shipping['pickup']['label'], 0, $shipping['pickup']['days']);
        }

        usort($options, static fn (ShippingOption $a, ShippingOption $b): int => $a->priceCents <=> $b->priceCents);

        return $options;
    }
}
