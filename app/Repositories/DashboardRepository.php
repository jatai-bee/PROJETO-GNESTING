<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

final class DashboardRepository extends Repository
{
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
}
