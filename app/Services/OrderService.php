<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Logger;
use GNesting\Enums\OrderStatus;
use GNesting\Repositories\OrderRepository;

/**
 * Transições automáticas (pagamento e expiração). As mudanças de status passam
 * sempre pelo OrderStatusService (único ponto que altera orders.status).
 */
final class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderStatusService $status,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Pagamento aprovado: awaiting_payment → paid → production_pending (automático na 1ª versão).
     *
     * @return bool true se o pedido foi confirmado agora
     */
    public function markPaid(int $orderId, string $source): bool
    {
        $order = $this->orders->find($orderId) ?? throw new BusinessRuleException('Pedido não encontrado.');
        $current = OrderStatus::from((string) $order['status']);

        if ($current === OrderStatus::Cancelled) {
            // Pagamento chegou depois do cancelamento: exige ação humana (estornar ou reativar)
            $this->orders->setPaymentStatus($orderId, 'paid');
            $this->orders->appendAdminNote($orderId, 'Pagamento aprovado após o cancelamento. Estorne o valor ao cliente.');
            $this->logger->warning('Pagamento aprovado para pedido cancelado', ['order' => $order['number']]);

            return false;
        }
        if ($current !== OrderStatus::AwaitingPayment) {
            return false; // já confirmado (aviso repetido)
        }

        $this->status->transition($orderId, OrderStatus::Paid, $source);
        $this->status->transition($orderId, OrderStatus::ProductionPending, 'system', null, 'Liberado para a fila de produção');

        return true;
    }

    /** Cancela pedido ainda não pago (reserva de estoque devolvida). */
    public function cancelUnpaid(int $orderId, string $reason, string $source): bool
    {
        $order = $this->orders->find($orderId);
        if ($order === null || $order['status'] !== OrderStatus::AwaitingPayment->value) {
            return false;
        }
        $this->status->cancel($orderId, $reason, $source, null, 'none');

        return true;
    }

    /** Cron: cancela pedidos sem pagamento após o prazo. @return int quantidade cancelada */
    public function expireUnpaid(int $hours): int
    {
        $count = 0;
        foreach ($this->orders->expiredUnpaidIds($hours) as $orderId) {
            try {
                if ($this->cancelUnpaid($orderId, "Pagamento não confirmado em {$hours} horas", 'system')) {
                    $count++;
                }
            } catch (BusinessRuleException $e) {
                $this->logger->warning('Não foi possível expirar pedido', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }
}
