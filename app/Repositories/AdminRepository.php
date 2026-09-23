<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;
use GNesting\Enums\AdminRole;

final class AdminRepository extends Repository
{
    /**
     * Administrador apto a entrar: perfil ativo + usuário ativo do tipo admin.
     *
     * @return array{admin_id:int,user_id:int,name:string,role:string,email:string}|null
     */
    public function findActiveByUserId(int $userId): ?array
    {
        return $this->fetchOne(
            "SELECT a.id AS admin_id, a.user_id, a.name, a.role, u.email
               FROM admins a
               JOIN users u ON u.id = a.user_id
              WHERE a.user_id = :user_id
                AND a.is_active = 1
                AND u.status = 'active'
                AND u.type = 'admin'
              LIMIT 1",
            ['user_id' => $userId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        return $this->fetchAll(
            "SELECT a.id AS admin_id, a.user_id, a.name, a.role, a.is_active, u.email, u.status, u.last_login_at, a.created_at
               FROM admins a
               JOIN users u ON u.id = a.user_id
              ORDER BY a.is_active DESC, FIELD(a.role, 'owner', 'manager', 'production', 'support'), a.name"
        );
    }

    /** @return array{admin_id:int,user_id:int,name:string,role:string,is_active:int,email:string,last_login_at:?string}|null */
    public function findById(int $adminId): ?array
    {
        return $this->fetchOne(
            'SELECT a.id AS admin_id, a.user_id, a.name, a.role, a.is_active, u.email, u.last_login_at
               FROM admins a
               JOIN users u ON u.id = a.user_id
              WHERE a.id = :id',
            ['id' => $adminId]
        );
    }

    public function update(int $adminId, string $name, AdminRole $role, bool $isActive): void
    {
        $this->execute(
            'UPDATE admins SET name = :name, role = :role, is_active = :active WHERE id = :id',
            ['name' => $name, 'role' => $role->value, 'active' => (int) $isActive, 'id' => $adminId]
        );
    }

    public function countActiveOwners(?int $exceptAdminId = null): int
    {
        return (int) $this->fetchValue(
            "SELECT COUNT(*) FROM admins a JOIN users u ON u.id = a.user_id
              WHERE a.role = 'owner' AND a.is_active = 1 AND u.status = 'active' AND a.id <> :except",
            ['except' => $exceptAdminId ?? 0]
        );
    }

    public function create(int $userId, string $name, AdminRole $role): int
    {
        return $this->insert(
            'INSERT INTO admins (user_id, name, role) VALUES (:user_id, :name, :role)',
            ['user_id' => $userId, 'name' => $name, 'role' => $role->value]
        );
    }
}
