<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/** Favoritos do cliente (tabela wishlists, desde a etapa 1). */
final class WishlistRepository extends Repository
{
    /** @return list<int> ids dos produtos favoritados, do mais recente para o mais antigo */
    public function productIds(int $customerId): array
    {
        return array_map('intval', array_column($this->fetchAll(
            'SELECT product_id FROM wishlists WHERE customer_id = :customer ORDER BY created_at DESC, product_id DESC',
            ['customer' => $customerId]
        ), 'product_id'));
    }

    /** @return list<array{id:int, name:string, is_active:int}> favoritos para a ficha do cliente no painel */
    public function productsForAdmin(int $customerId): array
    {
        return $this->fetchAll(
            'SELECT p.id, p.name, p.is_active FROM wishlists w JOIN products p ON p.id = w.product_id AND p.deleted_at IS NULL
              WHERE w.customer_id = :customer ORDER BY w.created_at DESC LIMIT 20',
            ['customer' => $customerId]
        );
    }

    public function has(int $customerId, int $productId): bool
    {
        return (bool) $this->fetchValue(
            'SELECT 1 FROM wishlists WHERE customer_id = :customer AND product_id = :product',
            ['customer' => $customerId, 'product' => $productId]
        );
    }

    public function add(int $customerId, int $productId): void
    {
        $this->execute(
            'INSERT IGNORE INTO wishlists (customer_id, product_id) VALUES (:customer, :product)',
            ['customer' => $customerId, 'product' => $productId]
        );
    }

    public function remove(int $customerId, int $productId): void
    {
        $this->execute(
            'DELETE FROM wishlists WHERE customer_id = :customer AND product_id = :product',
            ['customer' => $customerId, 'product' => $productId]
        );
    }
}
