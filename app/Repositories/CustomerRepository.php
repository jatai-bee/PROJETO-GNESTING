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

    public function attachUser(int $customerId, int $userId, string $name): void
    {
        $this->execute(
            'UPDATE customers SET user_id = :user_id, name = :name WHERE id = :id AND user_id IS NULL',
            ['user_id' => $userId, 'name' => $name, 'id' => $customerId]
        );
    }
}
