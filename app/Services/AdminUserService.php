<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Database;
use GNesting\Enums\AdminRole;
use GNesting\Repositories\AdminRepository;
use GNesting\Repositories\UserRepository;
use GNesting\Services\Auth\AuthService;
use GNesting\Services\Auth\PasswordHasher;

/**
 * Equipe do painel. Regras de proteção:
 * - ninguém altera o próprio papel nem se desativa (evita perder o acesso por engano)
 * - sempre existe pelo menos um proprietário ativo
 */
final class AdminUserService
{
    public const MIN_PASSWORD = 12;

    public function __construct(
        private readonly Database $db,
        private readonly AdminRepository $admins,
        private readonly UserRepository $users,
        private readonly AuthService $auth,
        private readonly PasswordHasher $hasher,
        private readonly AuditService $audit,
    ) {
    }

    public function create(string $name, string $email, AdminRole $role, #[\SensitiveParameter] string $password): int
    {
        return $this->auth->createAdmin($name, $email, $password, $role);
    }

    public function update(int $adminId, string $name, AdminRole $role, bool $isActive, int $actingAdminId): void
    {
        $this->db->transaction(function () use ($adminId, $name, $role, $isActive, $actingAdminId): void {
            $current = $this->admins->findById($adminId) ?? throw new BusinessRuleException('Usuário não encontrado.');

            if ($adminId === $actingAdminId && ($role->value !== $current['role'] || !$isActive)) {
                throw new BusinessRuleException('Você não pode alterar o próprio papel nem desativar a própria conta.');
            }

            $losesOwner = $current['role'] === AdminRole::Owner->value && (bool) $current['is_active']
                && ($role !== AdminRole::Owner || !$isActive);
            if ($losesOwner && $this->admins->countActiveOwners($adminId) === 0) {
                throw new BusinessRuleException('É preciso manter pelo menos um proprietário ativo.');
            }

            $this->admins->update($adminId, $name, $role, $isActive);
            $this->audit->recordChanges(AuditService::UPDATE, 'admin', $adminId, $current, [
                'name' => $name, 'role' => $role->value, 'is_active' => (int) $isActive,
            ]);
        });
    }

    public function resetPassword(int $adminId, #[\SensitiveParameter] string $password): void
    {
        $admin = $this->admins->findById($adminId) ?? throw new BusinessRuleException('Usuário não encontrado.');
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new BusinessRuleException('A senha deve ter pelo menos ' . self::MIN_PASSWORD . ' caracteres.');
        }

        $this->users->updatePasswordHash((int) $admin['user_id'], $this->hasher->hash($password));
        $this->audit->record(AuditService::UPDATE, 'admin', $adminId, null, ['password' => '[alterada]']);
    }
}
