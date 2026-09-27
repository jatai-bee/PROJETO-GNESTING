<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/**
 * Variantes (SKUs). Todo produto tem uma variante padrão (is_default = 1),
 * editada no formulário do produto; as demais são geridas na aba Variações.
 */
final class ProductVariantRepository extends Repository
{
    private const FIELDS = 'v.id, v.product_id, v.sku, v.name, v.price_cents, v.compare_at_price_cents, v.cost_cents, v.material_label, v.finish_label,
        v.width_mm, v.height_mm, v.depth_mm, v.weight_g, v.package_width_mm, v.package_height_mm, v.package_length_mm, v.package_weight_g,
        v.is_default, v.is_active, v.sort_order,
        COALESCE(i.stock_mode, \'made_to_order\') AS stock_mode, COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand,
        COALESCE(i.quantity_reserved, 0) AS quantity_reserved, i.variant_id IS NOT NULL AS has_inventory';

    /** @return list<array<string, mixed>> variantes não excluídas, padrão primeiro */
    public function listByProduct(int $productId): array
    {
        return $this->fetchAll(
            'SELECT ' . self::FIELDS . ' FROM product_variants v LEFT JOIN inventory i ON i.variant_id = v.id
              WHERE v.product_id = :id AND v.deleted_at IS NULL
              ORDER BY v.is_default DESC, v.sort_order, v.id',
            ['id' => $productId]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $productId, int $variantId): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::FIELDS . ' FROM product_variants v LEFT JOIN inventory i ON i.variant_id = v.id
              WHERE v.id = :id AND v.product_id = :product_id AND v.deleted_at IS NULL',
            ['id' => $variantId, 'product_id' => $productId]
        );
    }

    public function countByProduct(int $productId): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM product_variants WHERE product_id = :id AND deleted_at IS NULL',
            ['id' => $productId]
        );
    }

    /** @param array<string, mixed> $data campos de createDefault() + name */
    public function create(int $productId, array $data): int
    {
        return $this->insert(
            'INSERT INTO product_variants (product_id, sku, name, price_cents, compare_at_price_cents, cost_cents, material_label, finish_label,
                                           width_mm, height_mm, depth_mm, weight_g,
                                           package_width_mm, package_height_mm, package_length_mm, package_weight_g,
                                           is_default, sort_order)
             SELECT :product_id, :sku, :name, :price_cents, :compare_at_price_cents, :cost_cents, :material_label, :finish_label,
                    :width_mm, :height_mm, :depth_mm, :weight_g,
                    :package_width_mm, :package_height_mm, :package_length_mm, :package_weight_g,
                    0, COALESCE(MAX(sort_order), 0) + 10
               FROM product_variants WHERE product_id = :product_id2',
            $data + ['product_id' => $productId, 'product_id2' => $productId]
        );
    }

    public function setName(int $variantId, ?string $name): void
    {
        $this->execute('UPDATE product_variants SET name = :name WHERE id = :id', ['name' => $name, 'id' => $variantId]);
    }

    public function setActive(int $variantId, bool $active): void
    {
        $this->execute('UPDATE product_variants SET is_active = :active WHERE id = :id', ['active' => (int) $active, 'id' => $variantId]);
    }

    /** Marca a variante como padrão e desmarca as outras do produto. */
    public function setDefault(int $productId, int $variantId): void
    {
        $this->execute(
            'UPDATE product_variants SET is_default = IF(id = :id, 1, 0) WHERE product_id = :product_id AND deleted_at IS NULL',
            ['id' => $variantId, 'product_id' => $productId]
        );
    }

    public function softDelete(int $variantId): void
    {
        $this->execute(
            'UPDATE product_variants SET deleted_at = UTC_TIMESTAMP(), is_active = 0, is_default = 0 WHERE id = :id AND deleted_at IS NULL',
            ['id' => $variantId]
        );
    }

    /** SKU é único para sempre, inclusive entre produtos excluídos (histórico de pedidos). */
    public function skuExists(string $sku, ?int $exceptVariantId = null): bool
    {
        return $this->fetchValue(
            'SELECT 1 FROM product_variants WHERE sku = :sku AND id <> :except LIMIT 1',
            ['sku' => $sku, 'except' => $exceptVariantId ?? 0]
        ) !== null;
    }

    /** @param array<string, mixed> $data */
    public function createDefault(int $productId, array $data): int
    {
        return $this->insert(
            'INSERT INTO product_variants (product_id, sku, price_cents, compare_at_price_cents, cost_cents, material_label, finish_label,
                                           width_mm, height_mm, depth_mm, weight_g,
                                           package_width_mm, package_height_mm, package_length_mm, package_weight_g, is_default)
             VALUES (:product_id, :sku, :price_cents, :compare_at_price_cents, :cost_cents, :material_label, :finish_label,
                     :width_mm, :height_mm, :depth_mm, :weight_g,
                     :package_width_mm, :package_height_mm, :package_length_mm, :package_weight_g, 1)',
            $data + ['product_id' => $productId]
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $variantId, array $data): void
    {
        $this->execute(
            'UPDATE product_variants
                SET sku = :sku, price_cents = :price_cents, compare_at_price_cents = :compare_at_price_cents, cost_cents = :cost_cents,
                    material_label = :material_label, finish_label = :finish_label,
                    width_mm = :width_mm, height_mm = :height_mm, depth_mm = :depth_mm, weight_g = :weight_g,
                    package_width_mm = :package_width_mm, package_height_mm = :package_height_mm,
                    package_length_mm = :package_length_mm, package_weight_g = :package_weight_g
              WHERE id = :id',
            $data + ['id' => $variantId]
        );
    }

    public function softDeleteByProduct(int $productId): void
    {
        $this->execute(
            'UPDATE product_variants SET deleted_at = UTC_TIMESTAMP(), is_active = 0 WHERE product_id = :id AND deleted_at IS NULL',
            ['id' => $productId]
        );
    }
}
