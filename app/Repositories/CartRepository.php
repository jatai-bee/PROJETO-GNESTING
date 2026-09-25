<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/**
 * Carrinhos e itens. O token do cookie nunca é gravado: só o SHA-256 dele.
 * Preços não ficam no carrinho (sempre recalculados — docs/02 §Carrinho).
 */
final class CartRepository extends Repository
{
    /** @return array{id: int, customer_id: ?int}|null carrinho ativo e não expirado */
    public function findActiveByTokenHash(string $tokenHash): ?array
    {
        return $this->fetchOne(
            "SELECT id, customer_id FROM carts
              WHERE token_hash = :hash AND status = 'active' AND expires_at > UTC_TIMESTAMP()",
            ['hash' => $tokenHash]
        );
    }

    public function create(string $tokenHash, ?int $customerId, int $lifetimeDays): int
    {
        return $this->insert(
            'INSERT INTO carts (token_hash, customer_id, expires_at)
             VALUES (:hash, :customer_id, UTC_TIMESTAMP() + INTERVAL :days DAY)',
            ['hash' => $tokenHash, 'customer_id' => $customerId, 'days' => $lifetimeDays]
        );
    }

    /** Renova a validade e, se houver cliente logado, associa o carrinho a ele. */
    public function touch(int $cartId, ?int $customerId, int $lifetimeDays): void
    {
        $this->execute(
            'UPDATE carts
                SET expires_at = UTC_TIMESTAMP() + INTERVAL :days DAY,
                    customer_id = COALESCE(:customer_id, customer_id)
              WHERE id = :id',
            ['days' => $lifetimeDays, 'customer_id' => $customerId, 'id' => $cartId]
        );
    }

    /** @return list<array{id: int, variant_id: int, quantity: int}> */
    public function items(int $cartId): array
    {
        return $this->fetchAll(
            'SELECT id, variant_id, quantity FROM cart_items WHERE cart_id = :cart_id ORDER BY created_at, id',
            ['cart_id' => $cartId]
        );
    }

    public function itemQuantity(int $cartId): int
    {
        return (int) $this->fetchValue(
            'SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE cart_id = :cart_id',
            ['cart_id' => $cartId]
        );
    }

    /** @return array{id: int, variant_id: int, quantity: int}|null */
    public function findItem(int $cartId, int $itemId): ?array
    {
        return $this->fetchOne(
            'SELECT id, variant_id, quantity FROM cart_items WHERE id = :id AND cart_id = :cart_id',
            ['id' => $itemId, 'cart_id' => $cartId]
        );
    }

    /** @return array{id: int, quantity: int}|null linha sem personalização da variante */
    public function findLine(int $cartId, int $variantId): ?array
    {
        return $this->fetchOne(
            "SELECT id, quantity FROM cart_items
              WHERE cart_id = :cart_id AND variant_id = :variant_id AND personalization_hash = ''",
            ['cart_id' => $cartId, 'variant_id' => $variantId]
        );
    }

    public function countLines(int $cartId): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM cart_items WHERE cart_id = :cart_id', ['cart_id' => $cartId]);
    }

    public function addItem(int $cartId, int $variantId, int $quantity): int
    {
        return $this->insert(
            'INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES (:cart_id, :variant_id, :quantity)',
            ['cart_id' => $cartId, 'variant_id' => $variantId, 'quantity' => $quantity]
        );
    }

    public function setQuantity(int $itemId, int $quantity): void
    {
        $this->execute('UPDATE cart_items SET quantity = :quantity WHERE id = :id', ['quantity' => $quantity, 'id' => $itemId]);
    }

    public function removeItem(int $cartId, int $itemId): void
    {
        $this->execute('DELETE FROM cart_items WHERE id = :id AND cart_id = :cart_id', ['id' => $itemId, 'cart_id' => $cartId]);
    }

    /** Limpeza periódica (cron): carrinhos expirados saem do banco junto com os itens. */
    public function purgeExpired(): int
    {
        return $this->execute("DELETE FROM carts WHERE status = 'active' AND expires_at < UTC_TIMESTAMP()");
    }
}
