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
