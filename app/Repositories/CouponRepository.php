<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

final class CouponRepository extends Repository
{
    private const FIELDS = 'id, code, description, type, value, min_subtotal_cents, max_discount_cents, starts_at, ends_at,
        usage_limit, usage_limit_per_customer, times_used, is_active, created_at, updated_at';

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->fetchAll(
            'SELECT ' . self::FIELDS . ',
                    (SELECT COALESCE(SUM(discount_cents), 0) FROM coupon_redemptions r WHERE r.coupon_id = coupons.id) AS discount_total
               FROM coupons ORDER BY is_active DESC, created_at DESC'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        return $this->fetchOne('SELECT ' . self::FIELDS . ' FROM coupons WHERE id = :id' . ($forUpdate ? ' FOR UPDATE' : ''), ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->fetchOne('SELECT ' . self::FIELDS . ' FROM coupons WHERE code = :code', ['code' => strtoupper($code)]);
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->fetchValue('SELECT 1 FROM coupons WHERE code = :code AND id <> :except', ['code' => $code, 'except' => $exceptId ?? 0]) !== null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO coupons (code, description, type, value, min_subtotal_cents, max_discount_cents, starts_at, ends_at,
                                  usage_limit, usage_limit_per_customer, is_active)
             VALUES (:code, :description, :type, :value, :min_subtotal_cents, :max_discount_cents, :starts_at, :ends_at,
                     :usage_limit, :usage_limit_per_customer, :is_active)',
            $data
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE coupons SET code = :code, description = :description, type = :type, value = :value,
                    min_subtotal_cents = :min_subtotal_cents, max_discount_cents = :max_discount_cents,
                    starts_at = :starts_at, ends_at = :ends_at, usage_limit = :usage_limit,
                    usage_limit_per_customer = :usage_limit_per_customer, is_active = :is_active
              WHERE id = :id',
            $data + ['id' => $id]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM coupons WHERE id = :id', ['id' => $id]);
    }

    public function redemptionsByCustomer(int $couponId, int $customerId): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = :coupon AND customer_id = :customer',
            ['coupon' => $couponId, 'customer' => $customerId]
        );
    }

    public function redeem(int $couponId, int $orderId, int $customerId, int $discountCents): void
    {
        $this->execute(
            'INSERT INTO coupon_redemptions (coupon_id, order_id, customer_id, discount_cents) VALUES (:coupon, :order, :customer, :discount)',
            ['coupon' => $couponId, 'order' => $orderId, 'customer' => $customerId, 'discount' => $discountCents]
        );
        $this->execute('UPDATE coupons SET times_used = times_used + 1 WHERE id = :id', ['id' => $couponId]);
    }

    /** Pedido cancelado: o uso do cupom é devolvido. @return bool havia uso a devolver */
    public function release(int $orderId): bool
    {
        $couponId = $this->fetchValue('SELECT coupon_id FROM coupon_redemptions WHERE order_id = :id', ['id' => $orderId]);
        if ($couponId === null) {
            return false;
        }
        $this->execute('DELETE FROM coupon_redemptions WHERE order_id = :id', ['id' => $orderId]);
        $this->execute('UPDATE coupons SET times_used = GREATEST(CAST(times_used AS SIGNED) - 1, 0) WHERE id = :id', ['id' => (int) $couponId]);

        return true;
    }
}
