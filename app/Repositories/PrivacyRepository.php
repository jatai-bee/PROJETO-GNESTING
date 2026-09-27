<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/** Consultas dos direitos do titular (LGPD): exportação e anonimização. */
final class PrivacyRepository extends Repository
{
    public const ANONYMOUS_NAME = 'Cliente anonimizado';

    /** @return array{id:int,user_id:?int,anonymized_at:?string}|null */
    public function lockCustomer(int $customerId): ?array
    {
        return $this->fetchOne(
            'SELECT id, user_id, anonymized_at FROM customers WHERE id = :id FOR UPDATE',
            ['id' => $customerId]
        );
    }

    /** @return array<string, mixed>|null */
    public function account(int $userId): ?array
    {
        return $this->fetchOne(
            'SELECT email, created_at, last_login_at, email_verified_at FROM users WHERE id = :id',
            ['id' => $userId]
        );
    }

    /** @return list<int> */
    public function orderIds(int $customerId): array
    {
        return array_map('intval', array_column($this->fetchAll(
            'SELECT id FROM orders WHERE customer_id = :id ORDER BY placed_at',
            ['id' => $customerId]
        ), 'id'));
    }

    /** @param list<string> $finalStatuses */
    public function openOrdersCount(int $customerId, array $finalStatuses): int
    {
        $params = ['id' => $customerId];
        $placeholders = [];
        foreach ($finalStatuses as $n => $status) {
            $placeholders[] = ':s' . $n;
            $params['s' . $n] = $status;
        }

        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM orders WHERE customer_id = :id AND status NOT IN (' . implode(', ', $placeholders) . ')',
            $params
        );
    }

    public function anonymizeCustomer(int $customerId): void
    {
        $this->execute(
            "UPDATE customers
                SET name = :name, email = CONCAT('anonimizado-', id, '@anonimizado.invalid'),
                    cpf = NULL, phone = NULL, whatsapp_opt_in = 0, marketing_opt_in = 0, notes = NULL,
                    user_id = NULL, anonymized_at = UTC_TIMESTAMP()
              WHERE id = :id",
            ['name' => self::ANONYMOUS_NAME, 'id' => $customerId]
        );
    }

    /** Endereços, carrinhos (com personalizações digitadas) e lista de desejos. */
    public function deleteCustomerData(int $customerId): void
    {
        foreach (['addresses', 'carts', 'wishlists', 'reviews'] as $table) {
            $this->execute("DELETE FROM {$table} WHERE customer_id = :id", ['id' => $customerId]);
        }
    }

    /** Apaga o login (tokens de senha caem em cascata). Só contas de cliente. */
    public function deleteCustomerUser(int $userId): void
    {
        $this->execute("DELETE FROM users WHERE id = :id AND type = 'customer'", ['id' => $userId]);
    }

    /** Apaga os dados pessoais dos pedidos feitos antes de $cutoff (UTC). Retorna quantos pedidos. */
    public function anonymizeOrdersBefore(int $customerId, string $cutoff): int
    {
        $count = $this->execute(
            "UPDATE orders
                SET customer_name = :name, customer_email = CONCAT('anonimizado-', customer_id, '@anonimizado.invalid'),
                    customer_phone = NULL, customer_cpf = NULL,
                    ship_recipient = :recipient, ship_zip_code = '00000000', ship_street = '—', ship_number = '—',
                    ship_complement = NULL, ship_district = '—'
              WHERE customer_id = :id AND placed_at < :cutoff",
            ['name' => self::ANONYMOUS_NAME, 'recipient' => self::ANONYMOUS_NAME, 'id' => $customerId, 'cutoff' => $cutoff]
        );
        // Textos digitados na personalização (nomes, datas, iniciais); opções de lista não identificam ninguém
        $this->execute(
            "UPDATE order_item_personalizations p
               JOIN order_items i ON i.id = p.order_item_id
               JOIN orders o ON o.id = i.order_id
                SET p.value_text = '[removido]'
              WHERE o.customer_id = :id AND o.placed_at < :cutoff AND p.type <> 'select'",
            ['id' => $customerId, 'cutoff' => $cutoff]
        );

        return $count;
    }
}
