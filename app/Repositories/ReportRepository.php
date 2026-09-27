<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/**
 * Relatórios por período (datas em UTC). Venda = pedido pago e não cancelado, pela data do pagamento.
 */
final class ReportRepository extends Repository
{
    private const SOLD = "o.paid_at IS NOT NULL AND o.status <> 'cancelled' AND o.paid_at >= :start AND o.paid_at < :end";

    /** @return list<array{product_id:?int, name:string, quantity:int, revenue:int, cost:int, with_cost:int}> */
    public function products(string $start, string $end): array
    {
        return array_map(static fn (array $r): array => [
            'product_id' => $r['product_id'] === null ? null : (int) $r['product_id'], 'name' => (string) $r['name'],
            'quantity' => (int) $r['quantity'], 'revenue' => (int) $r['revenue'], 'cost' => (int) $r['cost'], 'with_cost' => (int) $r['with_cost'],
        ], $this->fetchAll(
            'SELECT i.product_id, MAX(i.product_name) AS name, SUM(i.quantity) AS quantity, SUM(i.line_total_cents) AS revenue,
                    SUM(i.quantity * COALESCE(v.cost_cents, 0)) AS cost, MIN(v.cost_cents IS NOT NULL) AS with_cost
               FROM order_items i
               JOIN orders o ON o.id = i.order_id
               LEFT JOIN product_variants v ON v.id = i.variant_id
              WHERE ' . self::SOLD . '
              GROUP BY i.product_id ORDER BY revenue DESC, quantity DESC',
            ['start' => $start, 'end' => $end]
        ));
    }

    /** @return list<array{name:string, quantity:int, revenue:int}> por categoria principal */
    public function categories(string $start, string $end): array
    {
        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'quantity' => (int) $r['quantity'], 'revenue' => (int) $r['revenue']], $this->fetchAll(
            "SELECT COALESCE(parent.name, c.name, 'Sem categoria') AS name, SUM(i.quantity) AS quantity, SUM(i.line_total_cents) AS revenue
               FROM order_items i
               JOIN orders o ON o.id = i.order_id
               LEFT JOIN products p ON p.id = i.product_id
               LEFT JOIN categories c ON c.id = p.category_id
               LEFT JOIN categories parent ON parent.id = c.parent_id
              WHERE " . self::SOLD . '
              GROUP BY name ORDER BY revenue DESC',
            ['start' => $start, 'end' => $end]
        ));
    }

    /** @return list<array{customer_id:int, name:string, city:string, orders:int, spent:int, last_paid_at:string}> */
    public function customers(string $start, string $end, int $limit): array
    {
        return array_map(static fn (array $r): array => [
            'customer_id' => (int) $r['customer_id'], 'name' => (string) $r['name'], 'city' => (string) $r['city'],
            'orders' => (int) $r['orders'], 'spent' => (int) $r['spent'], 'last_paid_at' => (string) $r['last_paid_at'],
        ], $this->fetchAll(
            "SELECT o.customer_id, MAX(o.customer_name) AS name, MAX(CONCAT(o.ship_city, '/', o.ship_state)) AS city,
                    COUNT(*) AS orders, SUM(o.total_cents) AS spent, MAX(o.paid_at) AS last_paid_at
               FROM orders o WHERE " . self::SOLD . '
              GROUP BY o.customer_id ORDER BY spent DESC, orders DESC LIMIT :limit',
            ['start' => $start, 'end' => $end, 'limit' => $limit]
        ));
    }

    /**
     * Peças que terminaram a produção no período, com o prazo prometido do pedido.
     *
     * @return list<array{quantity:int, rework_count:int, estimated_minutes:int, finished_at:string, paid_at:?string, production_days:int}>
     */
    public function finishedJobs(string $start, string $end): array
    {
        return array_map(static fn (array $r): array => [
            'quantity' => (int) $r['quantity'], 'rework_count' => (int) $r['rework_count'], 'estimated_minutes' => (int) $r['estimated_minutes'],
            'finished_at' => (string) $r['finished_at'], 'paid_at' => $r['paid_at'] === null ? null : (string) $r['paid_at'],
            'production_days' => (int) $r['production_days'],
        ], $this->fetchAll(
            'SELECT j.quantity, j.rework_count, j.estimated_minutes, j.finished_at, o.paid_at, o.production_days
               FROM production_jobs j JOIN orders o ON o.id = j.order_id
              WHERE j.finished_at IS NOT NULL AND j.finished_at >= :start AND j.finished_at < :end',
            ['start' => $start, 'end' => $end]
        ));
    }

    /** @return array{cancelled:int, cancelled_cents:int, coupons:int, discount_cents:int, shipping_cents:int} */
    public function extras(string $start, string $end): array
    {
        $row = $this->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM orders WHERE status = 'cancelled' AND cancelled_at >= :s1 AND cancelled_at < :e1) AS cancelled,
                (SELECT COALESCE(SUM(total_cents), 0) FROM orders WHERE status = 'cancelled' AND cancelled_at >= :s2 AND cancelled_at < :e2) AS cancelled_cents,
                (SELECT COUNT(*) FROM orders o WHERE " . str_replace([':start', ':end'], [':s3', ':e3'], self::SOLD) . " AND o.coupon_id IS NOT NULL) AS coupons,
                (SELECT COALESCE(SUM(discount_cents), 0) FROM orders o WHERE " . str_replace([':start', ':end'], [':s4', ':e4'], self::SOLD) . ") AS discount_cents,
                (SELECT COALESCE(SUM(shipping_cents), 0) FROM orders o WHERE " . str_replace([':start', ':end'], [':s5', ':e5'], self::SOLD) . ') AS shipping_cents',
            ['s1' => $start, 'e1' => $end, 's2' => $start, 'e2' => $end, 's3' => $start, 'e3' => $end, 's4' => $start, 'e4' => $end, 's5' => $start, 'e5' => $end]
        ) ?? [];

        return array_map('intval', $row) + ['cancelled' => 0, 'cancelled_cents' => 0, 'coupons' => 0, 'discount_cents' => 0, 'shipping_cents' => 0];
    }
}
