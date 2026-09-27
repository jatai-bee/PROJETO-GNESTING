<?php

declare(strict_types=1);

namespace GNesting\Services\Shipping;

use GNesting\Core\Config;
use GNesting\Repositories\SettingsRepository;

/**
 * Frete em vigor: config/shipping.php (e .env) é o padrão; a tabela importada pelo painel
 * (Configurações → Importar YAML) fica na tabela settings e prevalece. Assim a tabela de frete
 * muda sem editar código nem usar Terminal.
 *
 * @phpstan-type Rates array<string, array<string, array{0: int, 1: int, 2: int}>>
 * @phpstan-type Effective array{origin_state: string, free_shipping_min_cents: int|null, free_shipping_service: string, pickup: array{enabled: bool, label: string, days: int}, table: Rates}
 */
final class ShippingSettings
{
    public const KEY = 'shipping.override';
    /** local = mesma UF da origem; demais = região de destino */
    public const BANDS = ['local', 'N', 'NE', 'CO', 'SE', 'S'];

    /** @var Effective|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Config $config,
        private readonly SettingsRepository $settings,
    ) {
    }

    /** @return array<string, array{carrier: string, label: string}> serviços oferecidos (config/shipping.php) */
    public function services(): array
    {
        return (array) $this->config->get('shipping.services', []);
    }

    /** @return Effective */
    public function effective(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $defaults = self::fromConfig($this->config);
        $override = json_decode($this->settings->get(self::KEY) ?? '', true);

        return $this->cache = is_array($override) ? array_replace($defaults, array_intersect_key($override, $defaults)) : $defaults;
    }

    /** @param Effective $values */
    public function save(array $values): void
    {
        $this->settings->set(self::KEY, (string) json_encode($values, JSON_UNESCAPED_UNICODE));
        $this->cache = null;
    }

    /** @return Effective */
    public static function fromConfig(Config $config): array
    {
        $pickup = (array) $config->get('shipping.pickup', []);
        $free = $config->get('shipping.free_shipping_min_cents');

        return [
            'origin_state' => (string) $config->get('shipping.origin_state', ''),
            'free_shipping_min_cents' => $free === null ? null : (int) $free,
            'free_shipping_service' => (string) $config->get('shipping.free_shipping_service', ''),
            'pickup' => [
                'enabled' => (bool) ($pickup['enabled'] ?? false),
                'label' => (string) ($pickup['label'] ?? 'Retirada no ateliê'),
                'days' => (int) ($pickup['days'] ?? 0),
            ],
            'table' => (array) $config->get('shipping.table', []),
        ];
    }
}
