<?php

declare(strict_types=1);

namespace GNesting\Services\Payment;

use GNesting\Core\Request;

/**
 * Pagamento SIMULADO para desenvolvimento e testes (PAYMENT_PROVIDER=simulado).
 * O "checkout" é uma página local (/pagamento-simulado/{referência}) com botões de aprovar,
 * deixar pendente ou recusar. Bloqueado em produção (Bootstrap e rota).
 */
final class SimulatedGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'simulado';
    }

    public function createCheckout(array $order, array $items, array $urls): array
    {
        $reference = 'SIM-' . bin2hex(random_bytes(8));

        return ['reference' => $reference, 'url' => url('/pagamento-simulado/' . $reference)];
    }

    /** Estorno simulado: sempre aceito. */
    public function refund(string $paymentId): void
    {
    }

    /** Não há provedor para consultar: a página simulada aplica o resultado diretamente. */
    public function fetchPayment(string $paymentId): ?GatewayPayment
    {
        return null;
    }

    public function parseWebhook(Request $request): array
    {
        return ['event_id' => 'sim-' . bin2hex(random_bytes(6)), 'type' => 'simulado', 'payment_id' => null, 'signature_valid' => false, 'payload' => '{}'];
    }
}
