<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

final class InventoryRepository extends Repository
{
    public function create(int $variantId, string $stockMode, int $quantity): void
    {
        $this->execute(
            'INSERT INTO inventory (variant_id, stock_mode, quantity_on_hand) VALUES (:variant_id, :mode, :qty)',
            ['variant_id' => $variantId, 'mode' => $stockMode, 'qty' => $quantity]
        );
    }

    /** @return array{stock_mode:string, quantity_on_hand:int, quantity_reserved:int}|null */
    public function find(int $variantId): ?array
    {
        return $this->fetchOne(
            'SELECT stock_mode, quantity_on_hand, quantity_reserved FROM inventory WHERE variant_id = :id',
            ['id' => $variantId]
        );
    }

    public function update(int $variantId, string $stockMode, int $quantity): void
    {
        $this->execute(
            'UPDATE inventory SET stock_mode = :mode, quantity_on_hand = :qty WHERE variant_id = :id',
            ['mode' => $stockMode, 'qty' => $quantity, 'id' => $variantId]
        );
    }

    /**
     * Reserva atômica para pedido: só reserva se houver saldo livre (evita vender a mesma
     * última unidade duas vezes). Variantes "sob encomenda" não reservam.
     *
     * @return bool false = sem saldo
     */
    public function reserve(int $variantId, int $quantity, int $orderId): bool
    {
        $updated = $this->execute(
            "UPDATE inventory SET quantity_reserved = quantity_reserved + :qty
              WHERE variant_id = :id AND stock_mode = 'stock' AND quantity_on_hand - quantity_reserved >= :qty_check",
            ['qty' => $quantity, 'id' => $variantId, 'qty_check' => $quantity]
        );
        if ($updated === 1) {
            $this->addOrderMovement($variantId, 'reserve', $quantity, 'Reserva do pedido', $orderId);
        }

        return $updated === 1;
    }

    public function isStockControlled(int $variantId): bool
    {
        return $this->fetchValue("SELECT 1 FROM inventory WHERE variant_id = :id AND stock_mode = 'stock'", ['id' => $variantId]) !== null;
    }

    /**
     * Reservas ainda abertas de um pedido: variant_id => quantidade.
     *
     * @return array<int, int>
     */
    public function openReservations(int $orderId): array
    {
        $rows = $this->fetchAll(
            // reserve é positivo; release e out são gravados negativos: a soma é o que segue reservado
            "SELECT variant_id, SUM(quantity) AS open_qty
               FROM inventory_movements
              WHERE reference_type = 'order' AND reference_id = :id AND type IN ('reserve', 'release', 'out')
              GROUP BY variant_id",
            ['id' => $orderId]
        );
        $open = [];
        foreach ($rows as $row) {
            if ((int) $row['open_qty'] > 0) {
                $open[(int) $row['variant_id']] = (int) $row['open_qty'];
            }
        }

        return $open;
    }

    /** Pedido cancelado/expirado: devolve a reserva ao saldo livre. */
    public function release(int $variantId, int $quantity, int $orderId, string $reason): void
    {
        $this->execute(
            'UPDATE inventory SET quantity_reserved = GREATEST(quantity_reserved - :qty, 0) WHERE variant_id = :id',
            ['qty' => $quantity, 'id' => $variantId]
        );
        $this->addOrderMovement($variantId, 'release', -$quantity, $reason, $orderId);
    }

    /** Pagamento aprovado: a reserva vira saída do estoque. */
    public function consume(int $variantId, int $quantity, int $orderId): void
    {
        $this->execute(
            'UPDATE inventory
                SET quantity_on_hand = GREATEST(quantity_on_hand - :qty, 0),
                    quantity_reserved = GREATEST(quantity_reserved - :qty2, 0)
              WHERE variant_id = :id',
            ['qty' => $quantity, 'qty2' => $quantity, 'id' => $variantId]
        );
        $this->addOrderMovement($variantId, 'out', -$quantity, 'Saída por pedido pago', $orderId);
    }

    private function addOrderMovement(int $variantId, string $type, int $quantity, string $reason, int $orderId): void
    {
        $this->execute(
            "INSERT INTO inventory_movements (variant_id, type, quantity, reason, reference_type, reference_id)
             VALUES (:variant_id, :type, :qty, :reason, 'order', :order_id)",
            ['variant_id' => $variantId, 'type' => $type, 'qty' => $quantity, 'reason' => $reason, 'order_id' => $orderId]
        );
    }

    /** Razão de movimentações: somente inserção. */
    public function addMovement(int $variantId, string $type, int $quantity, string $reason, ?int $userId): void
    {
        $this->execute(
            'INSERT INTO inventory_movements (variant_id, type, quantity, reason, user_id)
             VALUES (:variant_id, :type, :qty, :reason, :user_id)',
            ['variant_id' => $variantId, 'type' => $type, 'qty' => $quantity, 'reason' => $reason, 'user_id' => $userId]
        );
    }
}
