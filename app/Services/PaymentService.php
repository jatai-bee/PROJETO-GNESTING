<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Database;
use GNesting\Core\Logger;
use GNesting\Core\Request;
use GNesting\Enums\PaymentStatus;
use GNesting\Repositories\OrderRepository;
use GNesting\Services\Payment\GatewayPayment;
use GNesting\Services\Payment\PaymentGateway;
use Throwable;

/**
 * Pagamentos (docs/05 §10):
 * - checkout hospedado pelo provedor; o servidor só guarda referências e dados não sensíveis;
 * - o status só muda com a informação CONSULTADA no provedor (webhook assinado ou retorno
 *   do cliente → reconsulta na API). Parâmetros de URL nunca confirmam pagamento;
 * - avisos são idempotentes (payment_events único por provedor + id do evento);
 * - valor pago diferente do total do pedido não confirma o pedido.
 */
final class PaymentService
{
    public function __construct(
        private readonly Database $db,
        private readonly OrderRepository $orders,
        private readonly OrderService $orderService,
        private readonly PaymentGateway $gateway,
        private readonly Logger $logger,
    ) {
    }

    public function providerName(): string
    {
        return $this->gateway->name();
    }

    /**
     * Link de pagamento do pedido: reaproveita a tentativa aberta ou cria outra.
     *
     * @param array{success: string, pending: string, failure: string, notification: string} $urls
     * @throws BusinessRuleException pedido que não aceita pagamento
     * @throws \RuntimeException     provedor indisponível
     */
    public function checkoutUrl(int $orderId, array $urls): string
    {
        $order = $this->orders->find($orderId) ?? throw new BusinessRuleException('Pedido não encontrado.');
        if ($order['status'] !== 'awaiting_payment') {
            throw new BusinessRuleException('Este pedido não está aguardando pagamento.');
        }

        $open = $this->orders->openCheckout($orderId);
        if ($open !== null && $open['provider'] === $this->gateway->name() && (int) $open['amount_cents'] === (int) $order['total_cents']) {
            return (string) $open['checkout_url'];
        }

        $paymentId = $this->orders->createPayment($orderId, ['provider' => $this->gateway->name(), 'amount_cents' => (int) $order['total_cents']]);
        $session = $this->gateway->createCheckout($order, $this->orders->items($orderId), $urls);
        $this->orders->setCheckout($paymentId, $session['reference'], $session['url']);

        return $session['url'];
    }

    /**
     * Aviso do provedor. Retorna o status HTTP a responder (2xx = recebido; o provedor
     * reenvia em caso de erro).
     */
    public function handleWebhook(Request $request): int
    {
        $event = $this->gateway->parseWebhook($request);
        $eventId = $this->orders->recordEvent($this->gateway->name(), $event['event_id'], $event['type'], $this->jsonPayload($event['payload']), $event['signature_valid']);

        if (!$event['signature_valid']) {
            $this->logger->warning('Webhook de pagamento com assinatura inválida', ['provider' => $this->gateway->name(), 'type' => $event['type']]);
            if ($eventId !== null) {
                $this->orders->finishEvent($eventId, null, 'assinatura inválida');
            }

            return 401;
        }
        if ($eventId === null) {
            return 200; // aviso repetido: já processado
        }
        if ($event['payment_id'] === null) {
            $this->orders->finishEvent($eventId, null, null); // outros tipos de aviso: só registrados

            return 200;
        }

        try {
            $paymentId = $this->sync($event['payment_id'], 'webhook');
            $this->orders->finishEvent($eventId, $paymentId, null);

            return 200;
        } catch (Throwable $e) {
            $this->orders->finishEvent($eventId, null, $e->getMessage());
            $this->logger->error('Falha ao processar webhook de pagamento', ['provider' => $this->gateway->name(), 'error' => $e->getMessage()]);

            return 500;
        }
    }

    /**
     * Reconsulta o pagamento no provedor e aplica. Usado pelo webhook e pelo retorno do cliente.
     *
     * @return int|null id local do pagamento
     */
    public function sync(string $providerPaymentId, string $source): ?int
    {
        $payment = $this->gateway->fetchPayment($providerPaymentId);

        return $payment === null ? null : $this->apply($payment, $source);
    }

    /** Aplica a situação informada pelo provedor ao pagamento e ao pedido. */
    public function apply(GatewayPayment $payment, string $source): ?int
    {
        $provider = $this->gateway->name();

        [$localId, $orderId, $confirm] = $this->db->transaction(function () use ($payment, $provider): array {
            $order = $this->orders->findByNumber($payment->orderNumber, true);
            if ($order === null) {
                $this->logger->warning('Pagamento sem pedido correspondente', ['provider' => $provider, 'payment' => $payment->id]);

                return [null, null, false];
            }
            $orderId = (int) $order['id'];

            $local = $this->orders->findPaymentByProviderId($provider, $payment->id)
                ?? $this->orders->latestUnlinkedPayment($orderId, $provider);
            $localId = $local === null
                ? $this->orders->createLinkedPayment($orderId, $provider, $payment->id, $payment->amountCents)
                : (int) $local['id'];

            $amountMatches = $payment->amountCents === (int) $order['total_cents'];
            $this->orders->updatePayment($localId, [
                'provider_payment_id' => $payment->id,
                'status' => $payment->status->value,
                'method' => $payment->method,
                'installments' => $payment->installments,
                'card_brand' => $payment->cardBrand,
                'card_last4' => $payment->cardLast4,
                'failure_reason' => $payment->status === PaymentStatus::Paid && !$amountMatches
                    ? 'Valor pago (' . money($payment->amountCents) . ') diferente do total do pedido'
                    : $payment->failureReason,
            ]);

            $confirm = false;
            if ($payment->status === PaymentStatus::Paid) {
                if ($amountMatches) {
                    $confirm = true;
                } else {
                    $this->orders->appendAdminNote($orderId, "Pagamento {$payment->id} com valor divergente: " . money($payment->amountCents) . '. Pedido não confirmado automaticamente.');
                    $this->logger->warning('Pagamento com valor divergente', ['order' => $order['number'], 'payment' => $payment->id]);
                }
            } elseif (in_array($payment->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
                $this->orders->setPaymentStatus($orderId, $payment->status->value);
            } elseif ($order['status'] === 'awaiting_payment') {
                // recusado/pendente: o pedido continua aguardando (o cliente pode tentar de novo)
                $this->orders->setPaymentStatus($orderId, $payment->status === PaymentStatus::Failed ? 'failed' : 'pending');
            }

            return [$localId, $orderId, $confirm];
        });

        if ($confirm && $orderId !== null) {
            $this->orderService->markPaid($orderId, $source);
        }

        return $localId;
    }

    private function jsonPayload(string $payload): string
    {
        json_decode($payload);

        return json_last_error() === JSON_ERROR_NONE ? $payload : json_encode(['raw' => mb_substr($payload, 0, 5000)], JSON_UNESCAPED_UNICODE);
    }
}
