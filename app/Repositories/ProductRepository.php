<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

final class ProductRepository extends Repository
{
    /**
     * Filtros: q (nome ou SKU), category_id, status ('active' | 'inactive').
     *
     * @param array{q?: string, category_id?: int, status?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(array $filters): array
    {
        $sql = ' WHERE p.deleted_at IS NULL';
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $sql .= ' AND (p.name LIKE :q_name OR v.sku LIKE :q_sku)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $params['q_name'] = $like;
            $params['q_sku'] = $like;
        }
        if (($filters['category_id'] ?? 0) > 0) {
            $sql .= ' AND p.category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }
        if (($filters['status'] ?? '') === 'active') {
            $sql .= ' AND p.is_active = 1';
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $sql .= ' AND p.is_active = 0';
        }

        return [$sql, $params];
    }

    private const LIST_FROM = ' FROM products p
        JOIN categories c ON c.id = p.category_id
        LEFT JOIN product_variants v ON v.product_id = p.id AND v.is_default = 1 AND v.deleted_at IS NULL';

    /** @param array{q?: string, category_id?: int, status?: string} $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->where($filters);

        return (int) $this->fetchValue('SELECT COUNT(*)' . self::LIST_FROM . $where, $params);
    }

    /**
     * @param array{q?: string, category_id?: int, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function paginate(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filters);

        return $this->fetchAll(
            'SELECT p.id, p.name, p.slug, p.is_active, p.is_featured, p.is_new, p.updated_at,
                    c.name AS category_name, v.sku, v.price_cents,
                    (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_cover DESC, pi.sort_order, pi.id LIMIT 1) AS cover_path,
                    (SELECT COUNT(*) FROM product_images pi WHERE pi.product_id = p.id) AS image_count'
            . self::LIST_FROM . $where
            . ' ORDER BY p.updated_at DESC, p.id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
    }

    /**
     * Produto com a variante padrão e o estoque, para o formulário do admin.
     *
     * @return array<string, mixed>|null
     */
    public function findForAdmin(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT p.id, p.category_id, p.name, p.slug, p.short_description, p.description, p.highlights,
                    p.production_lead_days, p.is_active, p.is_featured, p.is_new, p.meta_title, p.meta_description,
                    p.published_at, p.created_at, p.updated_at,
                    v.id AS variant_id, v.sku, v.price_cents, v.compare_at_price_cents, v.material_label, v.finish_label,
                    v.width_mm, v.height_mm, v.depth_mm, v.weight_g,
                    v.package_width_mm, v.package_height_mm, v.package_length_mm, v.package_weight_g,
                    i.stock_mode, i.quantity_on_hand, i.quantity_reserved
               FROM products p
               LEFT JOIN product_variants v ON v.product_id = p.id AND v.is_default = 1 AND v.deleted_at IS NULL
               LEFT JOIN inventory i ON i.variant_id = v.id
              WHERE p.id = :id AND p.deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return $this->fetchValue(
            'SELECT 1 FROM products WHERE slug = :slug AND id <> :except LIMIT 1',
            ['slug' => $slug, 'except' => $exceptId ?? 0]
        ) !== null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO products (category_id, name, slug, short_description, description, highlights,
                                   production_lead_days, is_featured, is_new, meta_title, meta_description)
             VALUES (:category_id, :name, :slug, :short_description, :description, :highlights,
                     :production_lead_days, :is_featured, :is_new, :meta_title, :meta_description)',
            $data
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE products
                SET category_id = :category_id, name = :name, slug = :slug, short_description = :short_description,
                    description = :description, highlights = :highlights, production_lead_days = :production_lead_days,
                    is_featured = :is_featured, is_new = :is_new, meta_title = :meta_title, meta_description = :meta_description
              WHERE id = :id AND deleted_at IS NULL',
            $data + ['id' => $id]
        );
    }

    /**
     * "Mais vendidos": soma (+1) ou desfaz (-1, cancelamento de pedido pago) as quantidades do pedido.
     */
    public function applyOrderSales(int $orderId, int $direction): void
    {
        $this->execute(
            'UPDATE products p
               JOIN (SELECT product_id, SUM(quantity) AS qty FROM order_items WHERE order_id = :order_id GROUP BY product_id) s
                 ON s.product_id = p.id
                SET p.sales_count = GREATEST(CAST(p.sales_count AS SIGNED) + :direction * s.qty, 0)',
            ['order_id' => $orderId, 'direction' => $direction]
        );
    }

    public function setActive(int $id, bool $active): void
    {
        $this->execute(
            'UPDATE products
                SET is_active = :active, published_at = IF(:activating = 1 AND published_at IS NULL, UTC_TIMESTAMP(), published_at)
              WHERE id = :id AND deleted_at IS NULL',
            ['active' => (int) $active, 'activating' => (int) $active, 'id' => $id]
        );
    }

    /** Exclusão lógica: some da loja, libera o slug; o SKU continua reservado (histórico de pedidos). */
    public function softDelete(int $id): void
    {
        $this->execute(
            "UPDATE products
                SET deleted_at = UTC_TIMESTAMP(), is_active = 0, is_featured = 0,
                    slug = LEFT(CONCAT(slug, '-excluido-', id), 170)
              WHERE id = :id AND deleted_at IS NULL",
            ['id' => $id]
        );
    }

    public function categoryIsUsable(int $categoryId): bool
    {
        return $this->fetchValue(
            'SELECT 1 FROM categories WHERE id = :id AND deleted_at IS NULL',
            ['id' => $categoryId]
        ) !== null;
    }
}
