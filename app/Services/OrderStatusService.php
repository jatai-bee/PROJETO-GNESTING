<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Database;
use GNesting\Core\Logger;
use GNesting\Enums\AdminRole;
use GNesting\Enums\OrderStatus;
use GNesting\Repositories\InventoryRepository;
use GNesting\Repositories\OrderRepository;
use GNesting\Repositories\ProductRepository;
use GNesting\Services\Payment\PaymentGateway;
use Throwable;

/**
 * ÚNICO ponto que altera orders.status (docs/03 §3).
 *
 * Numa transação: confere a matriz do enum, atualiza o pedido, grava o histórico e a
 * auditoria e aplica os efeitos:
 * - paid: paid_at, "mais vendidos" (+), reserva de estoque vira saída;
 * - cancelled: motivo obrigatório; não pago → reserva devolvida; pago → estoque devolvido,
 *   "mais vendidos" (−) e estorno (pelo provedor ou registrado como manual);
 * - shipped: registra a remessa (transportadora, rastreio);
 * - delivered: remessa entregue.
 * Depois da transação, o cliente é avisado por e-mail (OrderNotifier).
 */
final class OrderStatusService
{
    /** Etapas que a equipe de produção move (docs/03 §5). */
    private const PRODUCTION_FLOW = [
        OrderStatus::ProductionPending, OrderStatus::InProduction, OrderStatus::Finishing,
        OrderStatus::QualityControl, OrderStatus::Packaging,
    ];

    public function __construct(
        private readonly Database $db,
        private readonly OrderRepository $orders,
        private readonly InventoryRepository $inventory,
        private readonly ProductRepository $products,
        private readonly AuditService $audit,
        private readonly OrderNotifier $notifier,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Para onde o papel pode mover o pedido a partir do status atual.
     * "Pago" nunca é manual: só o provedor de pagamento confirma.
     *
     * @return list<OrderStatus>
     */
    public static function targetsFor(AdminRole $role, OrderStatus $from): array
    {
        $allowed = array_values(array_filter($from->allowedTransitions(), static fn (OrderStatus $s): bool => $s !== OrderStatus::Paid));

        return match (true) {
            $role->isAllowed(['manager']) => $allowed,
            $role === AdminRole::Production && in_array($from, self::PRODUCTION_FLOW, true) => array_values(array_filter(
                $allowed,
                static fn (OrderStatus $s): bool => $s !== OrderStatus::Cancelled
            )),
            default => [],
        };
    }

    /**
     * @param array{carrier?: string, service?: ?string, tracking_code?: ?string, tracking_url?: ?string} $shipment (para "shipped")
     * @throws BusinessRuleException transição não permitida
     */
    public function transition(int $orderId, OrderStatus $to, string $source, ?int $userId = null, ?string $note = null, array $shipment = []): void
    {
        if ($to === OrderStatus::Cancelled) {
            throw new \LogicException('Use cancel() para cancelar (motivo e estorno são obrigatórios).');
        }

        $from = $this->db->transaction(function () use ($orderId, $to, $source, $userId, $note, $shipment): OrderStatus {
            $order = $this->orders->find($orderId, true) ?? throw new BusinessRuleException('Pedido não encontrado.');
            $from = OrderStatus::from((string) $order['status']);
            if (!$from->canTransitionTo($to)) {
                throw new BusinessRuleException("Não é possível passar de \"{$from->label()}\" para \"{$to->label()}\".");
            }

            switch ($to) {
                case OrderStatus::Paid:
                    $this->orders->updateStatus($orderId, $to->value, 'paid');
                    $this->products->applyOrderSales($orderId, 1);
                    foreach ($this->inventory->openReservations($orderId) as $variantId => $quantity) {
                        $this->inventory->consume($variantId, $quantity, $orderId);
                    }
                    break;

                case OrderStatus::Shipped:
                    $this->orders->updateStatus($orderId, $to->value);
                    $this->orders->createShipment($orderId, [
                        'carrier' => mb_substr(trim((string) ($shipment['carrier'] ?? '')) ?: (string) ($order['shipping_carrier'] ?? 'Transportadora'), 0, 60),
                        'service' => ($shipment['service'] ?? null) ?: $order['shipping_service'],
                        'tracking_code' => ($shipment['tracking_code'] ?? null) ?: null,
                        'tracking_url' => ($shipment['tracking_url'] ?? null) ?: null,
                    ]);
                    break;

                case OrderStatus::Delivered:
                    $this->orders->updateStatus($orderId, $to->value);
                    $this->orders->markShipmentsDelivered($orderId);
                    break;

                default:
                    $this->orders->updateStatus($orderId, $to->value);
            }

            $this->record($orderId, $from, $to, $source, $userId, $note);

            return $from;
        });

        $this->notify($orderId, $to);
    }

    /**
     * Cancela o pedido. Pedido pago exige estorno: 'gateway' (pelo provedor) ou 'manual'
     * (já devolvido por fora — fica registrado).
     *
     * @throws BusinessRuleException
     */
    public function cancel(int $orderId, string $reason, string $source, ?int $userId = null, string $refund = 'gateway'): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw new BusinessRuleException('Informe o motivo do cancelamento (até 200 caracteres).');
        }

        $order = $this->orders->find($orderId) ?? throw new BusinessRuleException('Pedido não encontrado.');
        $from = OrderStatus::from((string) $order['status']);
        if (!$from->canTransitionTo(OrderStatus::Cancelled)) {
            throw new BusinessRuleException("Pedido \"{$from->label()}\" não pode ser cancelado.");
        }

        // Estorno antes de cancelar: se o provedor recusar, o pedido fica como está
        $payment = $this->orders->paidPayment($orderId);
        $wasPaid = $payment !== null && $from !== OrderStatus::AwaitingPayment;
        if ($wasPaid) {
            $this->refund($payment, $refund, $orderId);
        }

        $this->db->transaction(function () use ($orderId, $reason, $source, $userId, $wasPaid): void {
            $order = $this->orders->find($orderId, true) ?? throw new BusinessRuleException('Pedido não encontrado.');
            $from = OrderStatus::from((string) $order['status']);
            if (!$from->canTransitionTo(OrderStatus::Cancelled)) {
                throw new BusinessRuleException('O pedido mudou de situação. Recarregue a página.');
            }

            if ($wasPaid) {
                $this->inventory->returnOrderStock($orderId);
                $this->products->applyOrderSales($orderId, -1);
                $paymentStatus = 'refunded';
            } else {
                foreach ($this->inventory->openReservations($orderId) as $variantId => $quantity) {
                    $this->inventory->release($variantId, $quantity, $orderId, 'Pedido cancelado: ' . $reason);
                }
                $paymentStatus = $order['payment_status'] === 'paid' ? 'paid' : 'cancelled';
            }

            $this->orders->updateStatus($orderId, OrderStatus::Cancelled->value, $paymentStatus);
            $this->orders->setCancelReason($orderId, $reason);
            $this->record($orderId, $from, OrderStatus::Cancelled, $source, $userId, $reason);
        });

        $this->notify($orderId, OrderStatus::Cancelled);
    }

    /** @param array<string, mixed> $payment */
    private function refund(array $payment, string $mode, int $orderId): void
    {
        if ($mode === 'manual') {
            $this->orders->markPaymentRefunded((int) $payment['id'], 'Estorno registrado manualmente no painel');
            $this->orders->appendAdminNote($orderId, 'Estorno marcado como feito fora da loja.');

            return;
        }
        if ($mode !== 'gateway') {
            throw new BusinessRuleException('Pedido pago: escolha como o valor será devolvido.');
        }

        $gateway = app(PaymentGateway::class);
        if ($payment['provider'] !== $gateway->name() || empty($payment['provider_payment_id'])) {
            throw new BusinessRuleException('Este pagamento não pode ser estornado automaticamente. Estorne no provedor e marque "estorno manual".');
        }
        try {
            $gateway->refund((string) $payment['provider_payment_id']);
        } catch (Throwable $e) {
            throw new BusinessRuleException('O provedor de pagamento não confirmou o estorno. Nada foi cancelado. Tente de novo ou estorne pelo painel do provedor.');
        }
        $this->orders->markPaymentRefunded((int) $payment['id'], 'Estornado pelo provedor');
    }

    private function record(int $orderId, OrderStatus $from, OrderStatus $to, string $source, ?int $userId, ?string $note): void
    {
        $this->orders->addHistory($orderId, $from->value, $to->value, $source, $userId, $note === null || $note === '' ? null : mb_substr($note, 0, 500));
        $this->audit->record(AuditService::STATUS_CHANGE, 'order', $orderId,
            ['status' => $from->value], ['status' => $to->value, 'source' => $source] + ($note ? ['note' => $note] : []));
    }

    private function notify(int $orderId, OrderStatus $status): void
    {
        // O e-mail nunca desfaz uma mudança de status já gravada
        try {
            $this->notifier->statusChanged($orderId, $status);
        } catch (Throwable $e) {
            $this->logger->error('Falha ao montar/enviar e-mail de status', ['order_id' => $orderId, 'status' => $status->value, 'error' => $e->getMessage()]);
        }
    }
}
