<?php

declare(strict_types=1);

namespace GNesting\Services\Payment;

use GNesting\Enums\PaymentStatus;

/**
 * Situação de um pagamento segundo o provedor (consultada na API, nunca vinda do navegador).
 */
final class GatewayPayment
{
    public function __construct(
        public readonly string $id,
        public readonly string $orderNumber,
        public readonly PaymentStatus $status,
        public readonly int $amountCents,
        public readonly string $method,          // pix | credit_card | boleto | other
        public readonly ?int $installments = null,
        public readonly ?string $cardBrand = null,
        public readonly ?string $cardLast4 = null,
        public readonly ?string $failureReason = null,
    ) {
    }
}
