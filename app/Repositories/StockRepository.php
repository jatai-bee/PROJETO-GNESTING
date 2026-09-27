<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/**
 * Tela de estoque: produto acabado (variações de pronta entrega) e matéria-prima, com a situação de cada item.
 * Situação: 'out' (sem nada disponível), 'low' (no mínimo ou abaixo), 'ok'. Produto sob encomenda é 'made'.
 */
final class StockRepository extends Repository
{
    private const STATE = "CASE
            WHEN COALESCE(i.stock_mode, 'made_to_order') <> 'stock' THEN 'made'
            WHEN COALESCE(i.quantity_on_hand, 0) - COALESCE(i.quantity_reserved, 0) <= 0 THEN 'out'
            WHEN i.reorder_level IS NOT NULL AND i.quantity_on_hand - i.quantity_reserved <= i.reorder_level THEN 'low'
            ELSE 'ok' END";

    /**
     * @param string $filter pronta | alerta | todos
     * @return list<array<string, mixed>>
     */
    public function finishedGoods(string $filter): array
    {
        $where = match ($filter) {
            'todos' => '',
            'alerta' => " AND COALESCE(i.stock_mode, 'made_to_order') = 'stock' AND (" . self::STATE . ") IN ('out', 'low')",
            default => " AND COALESCE(i.stock_mode, 'made_to_order') = 'stock'",
        };

        return $this->fetchAll(
            'SELECT v.id AS variant_id, p.id AS product_id, p.name, v.name AS variant_name, v.sku, p.is_active,
                    COALESCE(i.stock_mode, \'made_to_order\') AS stock_mode, COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand,
                    COALESCE(i.quantity_reserved, 0) AS quantity_reserved, i.reorder_level, v.cost_cents,
                    GREATEST(COALESCE(i.quantity_on_hand, 0) - COALESCE(i.quantity_reserved, 0), 0) AS available,
                    (' . self::STATE . ') AS state,
                    (SELECT MAX(m.created_at) FROM inventory_movements m WHERE m.variant_id = v.id) AS last_movement,
                    (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_cover DESC, pi.sort_order, pi.id LIMIT 1) AS cover_path
               FROM product_variants v
               JOIN products p ON p.id = v.product_id AND p.deleted_at IS NULL
               LEFT JOIN inventory i ON i.variant_id = v.id
              WHERE v.deleted_at IS NULL AND v.is_active = 1' . $where . "
              ORDER BY FIELD(state, 'out', 'low', 'ok', 'made'), p.name, v.sort_order, v.id"
        );
    }

    /** @return array{variants:int, out:int, low:int, units:int, value_cents:int, materials_low:int, materials_out:int, materials_value_cents:int} */
    public function summary(): array
    {
        $row = $this->fetchOne(
            "SELECT
                SUM(COALESCE(i.stock_mode, 'made_to_order') = 'stock') AS variants,
                SUM(COALESCE(i.stock_mode, 'made_to_order') = 'stock' AND (" . self::STATE . ") = 'out') AS `out`,
                SUM(COALESCE(i.stock_mode, 'made_to_order') = 'stock' AND (" . self::STATE . ") = 'low') AS low,
                COALESCE(SUM(IF(i.stock_mode = 'stock', i.quantity_on_hand, 0)), 0) AS units,
                COALESCE(SUM(IF(i.stock_mode = 'stock', i.quantity_on_hand * COALESCE(v.cost_cents, 0), 0)), 0) AS value_cents
               FROM product_variants v
               JOIN products p ON p.id = v.product_id AND p.deleted_at IS NULL
               LEFT JOIN inventory i ON i.variant_id = v.id
              WHERE v.deleted_at IS NULL AND v.is_active = 1"
        ) ?? [];
        $materials = $this->fetchOne(
            'SELECT SUM(reorder_level IS NOT NULL AND stock_qty > 0 AND stock_qty <= reorder_level) AS materials_low,
                    SUM(stock_qty <= 0) AS materials_out,
                    COALESCE(SUM(ROUND(stock_qty * COALESCE(cost_cents, 0))), 0) AS materials_value_cents
               FROM materials WHERE is_active = 1'
        ) ?? [];

        return array_map('intval', $row + $materials) + [
            'variants' => 0, 'out' => 0, 'low' => 0, 'units' => 0, 'value_cents' => 0,
            'materials_low' => 0, 'materials_out' => 0, 'materials_value_cents' => 0,
        ];
    }

    /** @return array{stock_mode:string, quantity_on_hand:int, quantity_reserved:int, reorder_level:?int}|null */
    public function lockInventory(int $variantId): ?array
    {
        return $this->fetchOne(
            'SELECT i.stock_mode, i.quantity_on_hand, i.quantity_reserved, i.reorder_level
               FROM inventory i JOIN product_variants v ON v.id = i.variant_id AND v.deleted_at IS NULL
              WHERE i.variant_id = :id FOR UPDATE',
            ['id' => $variantId]
        );
    }

    public function setQuantityAndMinimum(int $variantId, int $quantity, ?int $reorderLevel): void
    {
        $this->execute(
            'UPDATE inventory SET quantity_on_hand = :qty, reorder_level = :reorder WHERE variant_id = :id',
            ['qty' => $quantity, 'reorder' => $reorderLevel, 'id' => $variantId]
        );
    }
}
