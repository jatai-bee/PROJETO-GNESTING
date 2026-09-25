<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/**
 * Pedidos, itens (snapshots), histórico de status e pagamentos.
 * O pedido guarda cópias: mudanças futuras no catálogo não alteram o que foi comprado.
 */
final class OrderRepository extends Repository
{
    private const ORDER_FIELDS = 'id, number, access_token_hash, customer_id, status, payment_status, subtotal_cents, discount_cents,
        shipping_cents, total_cents, customer_name, customer_email, customer_phone, customer_cpf,
        ship_recipient, ship_zip_code, ship_street, ship_number, ship_complement, ship_district, ship_city, ship_state,
        shipping_carrier, shipping_service, shipping_days, production_days, placed_at, paid_at, cancelled_at, cancel_reason';

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $id = $this->insert(
            'INSERT INTO orders (number, access_token_hash, customer_id, subtotal_cents, discount_cents, shipping_cents, total_cents,
                                 customer_name, customer_email, customer_phone, customer_cpf,
                                 ship_recipient, ship_zip_code, ship_street, ship_number, ship_complement, ship_district, ship_city, ship_state,
                                 shipping_carrier, shipping_service, shipping_days, production_days, placed_at)
             VALUES (:number, :access_token_hash, :customer_id, :subtotal_cents, 0, :shipping_cents, :total_cents,
                     :customer_name, :customer_email, :customer_phone, :customer_cpf,
                     :ship_recipient, :ship_zip_code, :ship_street, :ship_number, :ship_complement, :ship_district, :ship_city, :ship_state,
                     :shipping_carrier, :shipping_service, :shipping_days, :production_days, UTC_TIMESTAMP())',
            $data + ['number' => 'TMP-' . bin2hex(random_bytes(8))]
        );

        // Número público sequencial por ano: GN-2026-000123 (id garante unicidade)
        $this->execute(
            "UPDATE orders SET number = CONCAT('GN-', YEAR(placed_at), '-', LPAD(id, 6, '0')) WHERE id = :id",
            ['id' => $id]
        );

        return $id;
    }

    /** @param array<string, mixed> $item */
    public function addItem(int $orderId, array $item): int
    {
        return $this->insert(
            'INSERT INTO order_items (order_id, product_id, variant_id, sku, product_name, variant_name, unit_price_cents,
                                      personalization_cents, quantity, line_total_cents, production_minutes_estimate)
             VALUES (:order_id, :product_id, :variant_id, :sku, :product_name, :variant_name, :unit_price_cents,
                     :personalization_cents, :quantity, :line_total_cents, :production_minutes_estimate)',
            $item + ['order_id' => $orderId]
        );
    }

    /** @param array<string, mixed> $data */
    public function addItemPersonalization(int $orderItemId, array $data): void
    {
        $this->execute(
            'INSERT INTO order_item_personalizations (order_item_id, rule_id, field_key, label, type, value_text, value_label, price_delta_cents)
             VALUES (:order_item_id, :rule_id, :field_key, :label, :type, :value_text, :value_label, :price_delta_cents)',
            $data + ['order_item_id' => $orderItemId]
        );
    }

    public function addHistory(int $orderId, ?string $from, string $to, string $source, ?int $userId = null, ?string $note = null): void
    {
        $this->execute(
            'INSERT INTO order_status_history (order_id, from_status, to_status, source, changed_by_user_id, note)
             VALUES (:order_id, :from_status, :to_status, :source, :user_id, :note)',
            ['order_id' => $orderId, 'from_status' => $from, 'to_status' => $to, 'source' => $source, 'user_id' => $userId, 'note' => $note]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        return $this->fetchOne('SELECT ' . self::ORDER_FIELDS . ' FROM orders WHERE id = :id' . ($forUpdate ? ' FOR UPDATE' : ''), ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByNumber(string $number, bool $forUpdate = false): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::ORDER_FIELDS . ' FROM orders WHERE number = :number' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['number' => $number]
        );
    }

    /** @return list<array<string, mixed>> */
    public function items(int $orderId): array
    {
        $items = $this->fetchAll(
            'SELECT oi.id, oi.product_id, oi.variant_id, oi.sku, oi.product_name, oi.variant_name, oi.unit_price_cents,
                    oi.personalization_cents, oi.quantity, oi.line_total_cents, p.slug AS product_slug
               FROM order_items oi
               LEFT JOIN products p ON p.id = oi.product_id AND p.deleted_at IS NULL
              WHERE oi.order_id = :id ORDER BY oi.id',
            ['id' => $orderId]
        );
        if ($items === []) {
            return [];
        }
        $choices = $this->fetchAll(
            'SELECT oip.order_item_id, oip.label, oip.type, oip.value_text, oip.value_label, oip.price_delta_cents
               FROM order_item_personalizations oip
               JOIN order_items oi ON oi.id = oip.order_item_id
              WHERE oi.order_id = :id ORDER BY oip.id',
            ['id' => $orderId]
        );
        foreach ($items as &$item) {
            $item['personalization'] = array_values(array_filter(
                $choices,
                static fn (array $c): bool => (int) $c['order_item_id'] === (int) $item['id']
            ));
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    public function history(int $orderId): array
    {
        return $this->fetchAll(
            'SELECT from_status, to_status, source, note, created_at FROM order_status_history WHERE order_id = :id ORDER BY id',
            ['id' => $orderId]
        );
    }

    /** @return list<array<string, mixed>> pedidos do cliente, mais recentes primeiro */
    public function forCustomer(int $customerId, int $limit = 50): array
    {
        return $this->fetchAll(
            'SELECT o.id, o.number, o.status, o.payment_status, o.total_cents, o.placed_at,
                    (SELECT COALESCE(SUM(quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
               FROM orders o WHERE o.customer_id = :id ORDER BY o.placed_at DESC, o.id DESC LIMIT :limit',
            ['id' => $customerId, 'limit' => $limit]
        );
    }

    public function updateStatus(int $orderId, string $status, ?string $paymentStatus = null): void
    {
        $this->execute(
            'UPDATE orders SET status = :status, payment_status = COALESCE(:payment_status, payment_status),
                    paid_at = IF(:paid = 1 AND paid_at IS NULL, UTC_TIMESTAMP(), paid_at),
                    cancelled_at = IF(:cancelled = 1, UTC_TIMESTAMP(), cancelled_at)
              WHERE id = :id',
            [
                'status' => $status, 'payment_status' => $paymentStatus, 'id' => $orderId,
                'paid' => (int) ($paymentStatus === 'paid'), 'cancelled' => (int) ($status === 'cancelled'),
            ]
        );
    }

    public function setPaymentStatus(int $orderId, string $paymentStatus): void
    {
        $this->execute('UPDATE orders SET payment_status = :ps WHERE id = :id', ['ps' => $paymentStatus, 'id' => $orderId]);
    }

    public function setCancelReason(int $orderId, string $reason): void
    {
        $this->execute('UPDATE orders SET cancel_reason = :reason WHERE id = :id', ['reason' => mb_substr($reason, 0, 200), 'id' => $orderId]);
    }

    public function appendAdminNote(int $orderId, string $note): void
    {
        $this->execute(
            "UPDATE orders SET admin_notes = TRIM(CONCAT(COALESCE(admin_notes, ''), '\n', :note)) WHERE id = :id",
            ['note' => '[' . gmdate('Y-m-d H:i') . ' UTC] ' . $note, 'id' => $orderId]
        );
    }

    /**
     * Pedidos aguardando pagamento criados antes do limite (expiração pelo cron).
     *
     * @return list<int>
     */
    public function expiredUnpaidIds(int $hours): array
    {
        return array_map('intval', array_column($this->fetchAll(
            "SELECT id FROM orders WHERE status = 'awaiting_payment' AND placed_at < UTC_TIMESTAMP() - INTERVAL :hours HOUR",
            ['hours' => $hours]
        ), 'id'));
    }

    // ---- Pagamentos -------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function createPayment(int $orderId, array $data): int
    {
        return $this->insert(
            "INSERT INTO payments (order_id, provider, method, status, amount_cents)
             VALUES (:order_id, :provider, 'checkout', 'pending', :amount_cents)",
            $data + ['order_id' => $orderId]
        );
    }

    public function setCheckout(int $paymentId, string $reference, string $url): void
    {
        $this->execute(
            'UPDATE payments SET checkout_reference = :ref, checkout_url = :url WHERE id = :id',
            ['ref' => $reference, 'url' => $url, 'id' => $paymentId]
        );
    }

    /** @return array<string, mixed>|null tentativa de checkout ainda aberta do pedido */
    public function openCheckout(int $orderId): ?array
    {
        return $this->fetchOne(
            "SELECT id, provider, checkout_reference, checkout_url, amount_cents FROM payments
              WHERE order_id = :id AND status = 'pending' AND provider_payment_id IS NULL AND checkout_url IS NOT NULL
              ORDER BY id DESC LIMIT 1",
            ['id' => $orderId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findPaymentByProviderId(string $provider, string $providerPaymentId): ?array
    {
        return $this->fetchOne(
            'SELECT id, order_id, status FROM payments WHERE provider = :provider AND provider_payment_id = :pid',
            ['provider' => $provider, 'pid' => $providerPaymentId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findPaymentByCheckout(string $provider, string $reference): ?array
    {
        return $this->fetchOne(
            'SELECT p.id, p.order_id, p.status, p.amount_cents, p.checkout_reference, o.number AS order_number
               FROM payments p JOIN orders o ON o.id = p.order_id
              WHERE p.provider = :provider AND p.checkout_reference = :ref',
            ['provider' => $provider, 'ref' => $reference]
        );
    }

    /** @return array<string, mixed>|null tentativa mais recente sem id do provedor */
    public function latestUnlinkedPayment(int $orderId, string $provider): ?array
    {
        return $this->fetchOne(
            'SELECT id, status FROM payments WHERE order_id = :id AND provider = :provider AND provider_payment_id IS NULL
              ORDER BY id DESC LIMIT 1',
            ['id' => $orderId, 'provider' => $provider]
        );
    }

    /** @param array<string, mixed> $data */
    public function createLinkedPayment(int $orderId, string $provider, string $providerPaymentId, int $amountCents): int
    {
        return $this->insert(
            "INSERT INTO payments (order_id, provider, provider_payment_id, method, status, amount_cents)
             VALUES (:order_id, :provider, :pid, 'checkout', 'pending', :amount)",
            ['order_id' => $orderId, 'provider' => $provider, 'pid' => $providerPaymentId, 'amount' => $amountCents]
        );
    }

    /** @param array<string, mixed> $data status, method, installments, card_brand, card_last4, failure_reason, provider_payment_id */
    public function updatePayment(int $paymentId, array $data): void
    {
        $this->execute(
            'UPDATE payments
                SET provider_payment_id = :provider_payment_id, status = :status, method = :method,
                    installments = :installments, card_brand = :card_brand, card_last4 = :card_last4,
                    failure_reason = :failure_reason,
                    paid_at = IF(:paid = 1 AND paid_at IS NULL, UTC_TIMESTAMP(), paid_at),
                    refunded_at = IF(:refunded = 1 AND refunded_at IS NULL, UTC_TIMESTAMP(), refunded_at),
                    refunded_cents = IF(:refunded2 = 1, amount_cents, refunded_cents)
              WHERE id = :id',
            $data + [
                'id' => $paymentId,
                'paid' => (int) ($data['status'] === 'paid'),
                'refunded' => (int) ($data['status'] === 'refunded'),
                'refunded2' => (int) ($data['status'] === 'refunded'),
            ]
        );
    }

    /** @return list<array<string, mixed>> */
    public function payments(int $orderId): array
    {
        return $this->fetchAll(
            'SELECT id, provider, method, status, amount_cents, installments, card_brand, card_last4, paid_at, created_at
               FROM payments WHERE order_id = :id ORDER BY id',
            ['id' => $orderId]
        );
    }

    /** Registra o evento; false se já existia (entrega repetida do mesmo aviso). */
    public function recordEvent(string $provider, string $eventId, string $type, string $payload, bool $signatureValid): ?int
    {
        $existing = $this->fetchValue(
            'SELECT id FROM payment_events WHERE provider = :provider AND event_id = :event_id',
            ['provider' => $provider, 'event_id' => $eventId]
        );
        if ($existing !== null) {
            return null;
        }

        return $this->insert(
            'INSERT INTO payment_events (provider, event_id, event_type, payload, signature_valid)
             VALUES (:provider, :event_id, :type, :payload, :valid)',
            ['provider' => $provider, 'event_id' => $eventId, 'type' => $type, 'payload' => $payload, 'valid' => (int) $signatureValid]
        );
    }

    public function finishEvent(int $eventId, ?int $paymentId, ?string $error): void
    {
        $this->execute(
            'UPDATE payment_events SET payment_id = :payment_id, processed_at = UTC_TIMESTAMP(), error = :error WHERE id = :id',
            ['payment_id' => $paymentId, 'error' => $error === null ? null : mb_substr($error, 0, 500), 'id' => $eventId]
        );
    }
}
