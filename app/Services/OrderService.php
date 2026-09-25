<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Database;
use GNesting\Core\Logger;
use GNesting\Enums\OrderStatus;
use GNesting\Repositories\InventoryRepository;
use GNesting\Repositories\OrderRepository;

/**
 * Transições automáticas do pedido nesta etapa (docs/03):
 * - pagamento aprovado: awaiting_payment → paid → production_pending (automático na 1ª versão),
 *   e a reserva de estoque vira saída;
 * - pedido não pago expirado/cancelado: awaiting_payment → cancelled, reserva devolvida.
 * Transições manuais do painel (produção, envio, cancelamento de pedido pago) são da etapa 8.
 */
final class OrderService
{
    public function __construct(
        private readonly Database $db,
        private readonly OrderRepository $orders,
        private readonly InventoryRepository $inventory,
        private readonly AuditService $audit,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return bool true se o pedido foi confirmado agora (false = já estava pago ou foi cancelado antes)
     */
    public function markPaid(int $orderId, string $source): bool
    {
        return $this->db->transaction(function () use ($orderId, $source): bool {
            $order = $this->orders->find($orderId, true) ?? throw new BusinessRuleException('Pedido não encontrado.');
            $status = OrderStatus::from((string) $order['status']);

            if ($status === OrderStatus::Cancelled) {
                // Pagamento chegou depois do cancelamento: exige ação humana (estornar ou reativar)
                $this->orders->setPaymentStatus($orderId, 'paid');
                $this->orders->appendAdminNote($orderId, 'Pagamento aprovado após o cancelamento. Estorne ou reative o pedido.');
                $this->logger->warning('Pagamento aprovado para pedido cancelado', ['order' => $order['number']]);

                return false;
            }
            if ($status !== OrderStatus::AwaitingPayment) {
                return false; // já confirmado (aviso repetido)
            }

            $this->orders->updateStatus($orderId, OrderStatus::Paid->value, 'paid');
            $this->orders->addHistory($orderId, OrderStatus::AwaitingPayment->value, OrderStatus::Paid->value, $source);
            $this->orders->updateStatus($orderId, OrderStatus::ProductionPending->value);
            $this->orders->addHistory($orderId, OrderStatus::Paid->value, OrderStatus::ProductionPending->value, 'system', null, 'Liberado para a fila de produção');

            foreach ($this->inventory->openReservations($orderId) as $variantId => $quantity) {
                $this->inventory->consume($variantId, $quantity, $orderId);
            }
            $this->audit->record(AuditService::STATUS_CHANGE, 'order', $orderId,
                ['status' => OrderStatus::AwaitingPayment->value], ['status' => OrderStatus::ProductionPending->value, 'source' => $source]);

            return true;
        });
    }

    /** Cancela pedido ainda não pago e devolve a reserva de estoque. */
    public function cancelUnpaid(int $orderId, string $reason, string $source): bool
    {
        return $this->db->transaction(function () use ($orderId, $reason, $source): bool {
            $order = $this->orders->find($orderId, true);
            if ($order === null || $order['status'] !== OrderStatus::AwaitingPayment->value) {
                return false;
            }

            foreach ($this->inventory->openReservations($orderId) as $variantId => $quantity) {
                $this->inventory->release($variantId, $quantity, $orderId, 'Pedido cancelado: ' . $reason);
            }
            $this->orders->updateStatus($orderId, OrderStatus::Cancelled->value, $order['payment_status'] === 'pending' ? 'cancelled' : null);
            $this->orders->setCancelReason($orderId, $reason);
            $this->orders->addHistory($orderId, OrderStatus::AwaitingPayment->value, OrderStatus::Cancelled->value, $source, null, $reason);
            $this->audit->record(AuditService::STATUS_CHANGE, 'order', $orderId,
                ['status' => OrderStatus::AwaitingPayment->value], ['status' => OrderStatus::Cancelled->value, 'reason' => $reason]);

            return true;
        });
    }

    /** Cron: cancela pedidos sem pagamento após o prazo. @return int quantidade cancelada */
    public function expireUnpaid(int $hours): int
    {
        $count = 0;
        foreach ($this->orders->expiredUnpaidIds($hours) as $orderId) {
            if ($this->cancelUnpaid($orderId, "Pagamento não confirmado em {$hours} horas", 'system')) {
                $count++;
            }
        }

        return $count;
    }
}
