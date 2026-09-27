<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/**
 * Números do painel. Vendas = pedidos pagos e não cancelados, pela data do pagamento.
 * Datas entram em UTC (como no banco); $offset ("-03:00") agrupa os dias no fuso da loja.
 */
final class DashboardRepository extends Repository
{
    private const SOLD = "o.paid_at IS NOT NULL AND o.status <> 'cancelled' AND o.paid_at >= :start AND o.paid_at < :end";

    /** @return array{products:int, active_products:int, active_without_image:int, categories:int, orders_open:int, customers:int} */
    public function counters(): array
    {
        $row = $this->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM products WHERE deleted_at IS NULL) AS products,
                (SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND is_active = 1) AS active_products,
                (SELECT COUNT(*) FROM products p WHERE p.deleted_at IS NULL AND p.is_active = 1
                    AND NOT EXISTS (SELECT 1 FROM product_images i WHERE i.product_id = p.id)) AS active_without_image,
                (SELECT COUNT(*) FROM categories WHERE deleted_at IS NULL) AS categories,
                (SELECT COUNT(*) FROM orders WHERE status NOT IN ('delivered', 'cancelled')) AS orders_open,
                (SELECT COUNT(*) FROM customers) AS customers"
        ) ?? [];

        return array_map('intval', $row) + [
            'products' => 0, 'active_products' => 0, 'active_without_image' => 0, 'categories' => 0,
            'orders_open' => 0, 'customers' => 0,
        ];
    }

    /** @return array{revenue:int, orders:int, items:int, new_customers:int} */
    public function totals(string $startUtc, string $endUtc): array
    {
        $row = $this->fetchOne(
            'SELECT COALESCE(SUM(o.total_cents), 0) AS revenue, COUNT(*) AS orders,
                    COALESCE((SELECT SUM(i.quantity) FROM order_items i JOIN orders o ON o.id = i.order_id WHERE ' . str_replace([':start', ':end'], [':start2', ':end2'], self::SOLD) . '), 0) AS items,
                    (SELECT COUNT(*) FROM customers WHERE created_at >= :start3 AND created_at < :end3) AS new_customers
               FROM orders o WHERE ' . self::SOLD,
            ['start' => $startUtc, 'end' => $endUtc, 'start2' => $startUtc, 'end2' => $endUtc, 'start3' => $startUtc, 'end3' => $endUtc]
        ) ?? [];

        return array_map('intval', $row) + ['revenue' => 0, 'orders' => 0, 'items' => 0, 'new_customers' => 0];
    }

    /**
     * Vendas por dia no fuso da loja (dias sem venda não aparecem; o serviço preenche).
     *
     * @return array<string, array{revenue:int, orders:int}> 'Y-m-d' => valores
     */
    public function revenueByDay(string $startUtc, string $endUtc, string $offset): array
    {
        $days = [];
        foreach ($this->fetchAll(
            "SELECT DATE(CONVERT_TZ(o.paid_at, '+00:00', :offset)) AS day, SUM(o.total_cents) AS revenue, COUNT(*) AS orders
               FROM orders o WHERE " . self::SOLD . ' GROUP BY day ORDER BY day',
            ['offset' => $offset, 'start' => $startUtc, 'end' => $endUtc]
        ) as $row) {
            $days[(string) $row['day']] = ['revenue' => (int) $row['revenue'], 'orders' => (int) $row['orders']];
        }

        return $days;
    }

    /** @return list<array{product_id:int|null, name:string, quantity:int, revenue:int}> */
    public function topProducts(string $startUtc, string $endUtc, int $limit): array
    {
        return array_map(static fn (array $r): array => [
            'product_id' => $r['product_id'] === null ? null : (int) $r['product_id'],
            'name' => (string) $r['name'], 'quantity' => (int) $r['quantity'], 'revenue' => (int) $r['revenue'],
        ], $this->fetchAll(
            'SELECT i.product_id, MAX(i.product_name) AS name, SUM(i.quantity) AS quantity, SUM(i.line_total_cents) AS revenue
               FROM order_items i JOIN orders o ON o.id = i.order_id
              WHERE ' . self::SOLD . '
              GROUP BY i.product_id ORDER BY revenue DESC, quantity DESC LIMIT :limit',
            ['start' => $startUtc, 'end' => $endUtc, 'limit' => $limit]
        ));
    }

    /**
     * Vendas por categoria principal (subcategoria soma na mãe).
     *
     * @return list<array{name:string, revenue:int}>
     */
    public function revenueByCategory(string $startUtc, string $endUtc): array
    {
        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'revenue' => (int) $r['revenue']], $this->fetchAll(
            "SELECT COALESCE(parent.name, c.name, 'Sem categoria') AS name, SUM(i.line_total_cents) AS revenue
               FROM order_items i
               JOIN orders o ON o.id = i.order_id
               LEFT JOIN products p ON p.id = i.product_id
               LEFT JOIN categories c ON c.id = p.category_id
               LEFT JOIN categories parent ON parent.id = c.parent_id
              WHERE " . self::SOLD . '
              GROUP BY name ORDER BY revenue DESC',
            ['start' => $startUtc, 'end' => $endUtc]
        ));
    }

    /**
     * O que pede ação: matéria-prima no ponto de reposição, pronta entrega zerada,
     * pagamento parado há mais de um dia e produto ativo sem foto.
     *
     * @return array{low_materials: list<array{name:string, stock:float, reorder:float, unit:string}>, low_materials_total:int, sold_out:int, stale_payments:int, without_image:int}
     */
    public function alerts(): array
    {
        $materials = array_map(static fn (array $r): array => [
            'name' => (string) $r['name'], 'stock' => (float) $r['stock_qty'], 'reorder' => (float) $r['reorder_level'], 'unit' => (string) $r['unit'],
        ], $this->fetchAll(
            'SELECT name, stock_qty, reorder_level, unit FROM materials
              WHERE is_active = 1 AND reorder_level > 0 AND stock_qty <= reorder_level ORDER BY stock_qty / reorder_level, name LIMIT 3'
        ));
        $row = $this->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM materials WHERE is_active = 1 AND reorder_level > 0 AND stock_qty <= reorder_level) AS low_materials_total,
                (SELECT COUNT(*) FROM inventory inv
                   JOIN product_variants v ON v.id = inv.variant_id AND v.is_active = 1 AND v.deleted_at IS NULL
                   JOIN products p ON p.id = v.product_id AND p.is_active = 1 AND p.deleted_at IS NULL
                  WHERE inv.stock_mode = 'stock' AND inv.quantity_on_hand - inv.quantity_reserved <= 0) AS sold_out,
                (SELECT COUNT(*) FROM orders WHERE status = 'awaiting_payment' AND placed_at < UTC_TIMESTAMP() - INTERVAL 1 DAY) AS stale_payments,
                (SELECT COUNT(*) FROM products p WHERE p.deleted_at IS NULL AND p.is_active = 1
                    AND NOT EXISTS (SELECT 1 FROM product_images i WHERE i.product_id = p.id)) AS without_image"
        ) ?? [];

        return ['low_materials' => $materials] + array_map('intval', $row) + ['low_materials_total' => 0, 'sold_out' => 0, 'stale_payments' => 0, 'without_image' => 0];
    }

    /** @return list<array<string, mixed>> id, number, customer_name, total_cents, status, placed_at */
    public function recentOrders(int $limit): array
    {
        return $this->fetchAll(
            'SELECT id, number, customer_name, total_cents, status, placed_at FROM orders ORDER BY placed_at DESC, id DESC LIMIT :limit',
            ['limit' => $limit]
        );
    }
}
