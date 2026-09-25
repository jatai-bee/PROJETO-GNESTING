<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;
use GNesting\Enums\UserType;

final class UserRepository extends Repository
{
    /** @return array{id:int,email:string,password_hash:string,type:string,status:string}|null */
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne(
            'SELECT id, email, password_hash, type, status FROM users WHERE email = :email LIMIT 1',
            ['email' => $email]
        );
    }

    public function emailExists(string $email): bool
    {
        return $this->fetchValue('SELECT 1 FROM users WHERE email = :email LIMIT 1', ['email' => $email]) !== null;
    }

    public function create(string $email, string $passwordHash, UserType $type): int
    {
        return $this->insert(
            'INSERT INTO users (email, password_hash, type) VALUES (:email, :hash, :type)',
            ['email' => $email, 'hash' => $passwordHash, 'type' => $type->value]
        );
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $this->execute('UPDATE users SET password_hash = :hash WHERE id = :id', ['hash' => $passwordHash, 'id' => $id]);
    }

    public function markEmailVerified(int $id): void
    {
        $this->execute('UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = :id', ['id' => $id]);
    }

    public function touchLastLogin(int $id): void
    {
        $this->execute('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $id]);
    }
}
