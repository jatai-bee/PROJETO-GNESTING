<?php

declare(strict_types=1);

namespace GNesting\Core;

use GNesting\Repositories\AdminRepository;
use GNesting\Repositories\CustomerRepository;

/**
 * Estado de login guardado na sessão. Cliente e administrador usam chaves
 * separadas: sair do painel não desconecta a conta de cliente, e vice-versa.
 *
 * A cada consulta o perfil é relido do banco, então bloquear um usuário ou
 * trocar o papel de um admin vale imediatamente. A sessão guarda também um
 * "carimbo" da senha (16 hex do SHA-256 do hash): se a senha for trocada, as
 * outras sessões abertas daquele usuário caem na próxima requisição.
 */
final class Auth
{
    private const ADMIN_KEY = 'auth.admin';
    private const CUSTOMER_KEY = 'auth.customer';

    public function __construct(
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly AdminRepository $admins,
        private readonly CustomerRepository $customers,
        private readonly Config $config,
    ) {
    }

    /** @param array{user_id:int} $admin */
    public function loginAdmin(array $admin): void
    {
        $this->session->regenerate();
        $this->csrf->regenerate();
        $stamp = $this->admins->findActiveByUserId((int) $admin['user_id'])['password_stamp'] ?? null;
        $this->session->set(self::ADMIN_KEY, ['user_id' => (int) $admin['user_id'], 'login_at' => time(), 'stamp' => $stamp]);
    }

    /** @return array{admin_id:int,user_id:int,name:string,role:string,email:string,password_stamp:string}|null */
    public function admin(): ?array
    {
        $state = $this->session->get(self::ADMIN_KEY);
        if (!is_array($state) || !isset($state['user_id'], $state['login_at'])) {
            return null;
        }

        $maxAge = (int) $this->config->get('security.session.admin_absolute_hours', 12) * 3600;
        if (time() - (int) $state['login_at'] > $maxAge) {
            $this->logoutAdmin();

            return null;
        }

        $admin = $this->admins->findActiveByUserId((int) $state['user_id']);
        if ($admin === null || ($state['stamp'] ?? null) !== $admin['password_stamp']) {
            $this->logoutAdmin();

            return null;
        }

        return $admin;
    }

    /** Depois de o próprio admin trocar a senha: mantém esta sessão (as outras caem). */
    public function refreshAdminStamp(): void
    {
        $state = $this->session->get(self::ADMIN_KEY);
        if (is_array($state) && isset($state['user_id'])) {
            $state['stamp'] = $this->admins->findActiveByUserId((int) $state['user_id'])['password_stamp'] ?? null;
            $this->session->set(self::ADMIN_KEY, $state);
        }
    }

    public function logoutAdmin(): void
    {
        $this->session->remove(self::ADMIN_KEY);
        $this->session->regenerate();
        $this->csrf->regenerate();
    }

    /** @param array{user_id:int} $customer */
    public function loginCustomer(array $customer): void
    {
        $this->session->regenerate();
        $this->csrf->regenerate();
        $stamp = $this->customers->findActiveByUserId((int) $customer['user_id'])['password_stamp'] ?? null;
        $this->session->set(self::CUSTOMER_KEY, ['user_id' => (int) $customer['user_id'], 'stamp' => $stamp]);
    }

    /** @return array{customer_id:int,user_id:int,name:string,email:string,password_stamp:string}|null */
    public function customer(): ?array
    {
        $state = $this->session->get(self::CUSTOMER_KEY);
        if (!is_array($state) || !isset($state['user_id'])) {
            return null;
        }

        $customer = $this->customers->findActiveByUserId((int) $state['user_id']);
        if ($customer === null || ($state['stamp'] ?? null) !== $customer['password_stamp']) {
            $this->logoutCustomer();

            return null;
        }

        return $customer;
    }

    public function logoutCustomer(): void
    {
        $this->session->remove(self::CUSTOMER_KEY);
        $this->session->regenerate();
        $this->csrf->regenerate();
    }
}
