<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/**
 * Nesta etapa só a variante padrão (1 por produto) é gerenciada.
 * Variações adicionais chegam na etapa 5.
 */
final class ProductVariantRepository extends Repository
{
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
            'INSERT INTO product_variants (product_id, sku, price_cents, compare_at_price_cents, material_label, finish_label,
                                           width_mm, height_mm, depth_mm, weight_g,
                                           package_width_mm, package_height_mm, package_length_mm, package_weight_g, is_default)
             VALUES (:product_id, :sku, :price_cents, :compare_at_price_cents, :material_label, :finish_label,
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
                SET sku = :sku, price_cents = :price_cents, compare_at_price_cents = :compare_at_price_cents,
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
