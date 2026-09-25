<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

final class CustomerRepository extends Repository
{
    /** @return array{customer_id:int,user_id:int,name:string,email:string}|null */
    public function findActiveByUserId(int $userId): ?array
    {
        return $this->fetchOne(
            "SELECT c.id AS customer_id, c.user_id, c.name, c.email
               FROM customers c
               JOIN users u ON u.id = c.user_id
              WHERE c.user_id = :user_id
                AND u.status = 'active'
                AND u.type = 'customer'
              LIMIT 1",
            ['user_id' => $userId]
        );
    }

    /** Cliente que comprou como visitante (sem conta) com este e-mail. */
    public function findGuestByEmail(string $email): ?array
    {
        return $this->fetchOne(
            'SELECT id, name, email FROM customers WHERE email = :email AND user_id IS NULL ORDER BY id LIMIT 1',
            ['email' => $email]
        );
    }

    public function create(?int $userId, string $name, string $email): int
    {
        return $this->insert(
            'INSERT INTO customers (user_id, name, email) VALUES (:user_id, :name, :email)',
            ['user_id' => $userId, 'name' => $name, 'email' => $email]
        );
    }

    private const ADMIN_FROM = " FROM customers c
        LEFT JOIN (SELECT customer_id, COUNT(*) AS orders_count,
                          SUM(CASE WHEN paid_at IS NOT NULL AND status <> 'cancelled' THEN total_cents ELSE 0 END) AS spent_cents,
                          MAX(placed_at) AS last_order_at
                     FROM orders GROUP BY customer_id) o ON o.customer_id = c.id";

    /** @return array{0: string, 1: array<string, mixed>} */
    private function adminWhere(string $q): array
    {
        if ($q === '') {
            return ['', []];
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $digits = (string) preg_replace('/\D/', '', $q);
        $sql = ' WHERE (c.name LIKE :q1 OR c.email LIKE :q2' . (strlen($digits) >= 4 ? ' OR c.cpf LIKE :q3 OR c.phone LIKE :q4' : '') . ')';
        $params = ['q1' => $like, 'q2' => $like] + (strlen($digits) >= 4 ? ['q3' => "%{$digits}%", 'q4' => "%{$digits}%"] : []);

        return [$sql, $params];
    }

    public function adminCount(string $q): int
    {
        [$where, $params] = $this->adminWhere($q);

        return (int) $this->fetchValue('SELECT COUNT(*) FROM customers c' . $where, $params);
    }

    /** @return list<array<string, mixed>> */
    public function adminList(string $q, int $limit, int $offset): array
    {
        [$where, $params] = $this->adminWhere($q);

        return $this->fetchAll(
            'SELECT c.id, c.user_id, c.name, c.email, c.cpf, c.phone, c.created_at,
                    COALESCE(o.orders_count, 0) AS orders_count, COALESCE(o.spent_cents, 0) AS spent_cents, o.last_order_at'
            . self::ADMIN_FROM . $where . ' ORDER BY COALESCE(o.last_order_at, c.created_at) DESC, c.id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
    }

    /** @return array<string, mixed>|null */
    public function adminFind(int $customerId): ?array
    {
        return $this->fetchOne(
            'SELECT c.id, c.user_id, c.name, c.email, c.cpf, c.phone, c.whatsapp_opt_in, c.marketing_opt_in, c.created_at,
                    COALESCE(o.orders_count, 0) AS orders_count, COALESCE(o.spent_cents, 0) AS spent_cents, o.last_order_at'
            . self::ADMIN_FROM . ' WHERE c.id = :id',
            ['id' => $customerId]
        );
    }

    /** @return array{id:int,name:string,email:string,cpf:?string,phone:?string}|null */
    public function findContact(int $customerId): ?array
    {
        return $this->fetchOne('SELECT id, name, email, cpf, phone FROM customers WHERE id = :id', ['id' => $customerId]);
    }

    /** Dados usados no checkout (CPF e telefone só com dígitos). */
    public function updateContact(int $customerId, string $name, ?string $cpf, ?string $phone): void
    {
        $this->execute(
            'UPDATE customers SET name = :name, cpf = COALESCE(:cpf, cpf), phone = COALESCE(:phone, phone) WHERE id = :id',
            ['name' => $name, 'cpf' => $cpf, 'phone' => $phone, 'id' => $customerId]
        );
    }

    public function attachUser(int $customerId, int $userId, string $name): void
    {
        $this->execute(
            'UPDATE customers SET user_id = :user_id, name = :name WHERE id = :id AND user_id IS NULL',
            ['user_id' => $userId, 'name' => $name, 'id' => $customerId]
        );
    }
}
