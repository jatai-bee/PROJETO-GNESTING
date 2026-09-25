<?php

declare(strict_types=1);

namespace GNesting\Services\Shipping;

/** Uma opção de entrega cotada (preço e prazo de transporte, sem o prazo de produção). */
final class ShippingOption
{
    public function __construct(
        public readonly string $code,
        public readonly string $carrier,
        public readonly string $service,
        public readonly int $priceCents,
        public readonly int $days,
    ) {
    }

    /** @return array{code: string, carrier: string, service: string, price_cents: int, days: int} */
    public function toArray(): array
    {
        return [
            'code' => $this->code, 'carrier' => $this->carrier, 'service' => $this->service,
            'price_cents' => $this->priceCents, 'days' => $this->days,
        ];
    }
}
