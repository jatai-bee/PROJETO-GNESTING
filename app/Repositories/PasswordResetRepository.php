<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/** Tokens de redefinição de senha. Só o SHA-256 do token é gravado. */
final class PasswordResetRepository extends Repository
{
    public function create(int $userId, string $tokenHash, string $expiresAt): int
    {
        return $this->insert(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :hash, :expires_at)',
            ['user_id' => $userId, 'hash' => $tokenHash, 'expires_at' => $expiresAt]
        );
    }

    /** @return array{id:int,user_id:int,expires_at:string,used_at:?string,email:string,type:string,status:string}|null */
    public function findByHash(string $tokenHash, bool $forUpdate = false): ?array
    {
        return $this->fetchOne(
            'SELECT r.id, r.user_id, r.expires_at, r.used_at, u.email, u.type, u.status
               FROM password_resets r
               JOIN users u ON u.id = r.user_id
              WHERE r.token_hash = :hash
              LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['hash' => $tokenHash]
        );
    }

    /** Invalida todos os tokens ainda não usados do usuário (novo pedido ou senha trocada). */
    public function invalidateForUser(int $userId): void
    {
        $this->execute(
            'UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND used_at IS NULL',
            ['user_id' => $userId]
        );
    }

    public function markUsed(int $id): void
    {
        $this->execute('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $id]);
    }

    /** Limpeza periódica (cron): tokens vencidos há mais de um dia. */
    public function purgeExpired(): int
    {
        return $this->execute('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    }
}
