<?php

declare(strict_types=1);

namespace GNesting\Services\Auth;

use GNesting\Core\Config;
use GNesting\Core\Database;
use GNesting\Core\ValidationException;
use GNesting\Enums\AdminRole;
use GNesting\Enums\UserType;
use GNesting\Repositories\AdminRepository;
use GNesting\Repositories\CustomerRepository;
use GNesting\Repositories\UserRepository;
use GNesting\Services\AuditService;
use GNesting\Services\RateLimiter;

/**
 * Verificação de credenciais, cadastro e criação de administradores.
 * Não conhece HTTP nem sessão: quem guarda o login é GNesting\Core\Auth.
 */
final class AuthService
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly AdminRepository $admins,
        private readonly CustomerRepository $customers,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly AuditService $audit,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{admin_id:int,user_id:int,name:string,role:string,email:string}
     * @throws AuthenticationException|TooManyAttemptsException
     */
    public function attemptAdmin(string $email, #[\SensitiveParameter] string $password, string $ip): array
    {
        $email = self::normalizeEmail($email);

        try {
            /** @var array{admin_id:int,user_id:int,name:string,role:string,email:string} $admin */
            $admin = $this->attempt(
                UserType::Admin,
                $email,
                $password,
                $ip,
                fn (int $userId) => $this->admins->findActiveByUserId($userId),
            );
        } catch (AuthenticationException $e) {
            $this->audit->record(AuditService::LOGIN_FAILED, 'user', null, null, ['email' => $email, 'area' => 'admin']);
            throw $e;
        }

        $this->audit->record(AuditService::LOGIN, 'admin', $admin['admin_id'], userId: $admin['user_id']);

        return $admin;
    }

    /**
     * @return array{customer_id:int,user_id:int,name:string,email:string}
     * @throws AuthenticationException|TooManyAttemptsException
     */
    public function attemptCustomer(string $email, #[\SensitiveParameter] string $password, string $ip): array
    {
        /** @var array{customer_id:int,user_id:int,name:string,email:string} */
        return $this->attempt(
            UserType::Customer,
            self::normalizeEmail($email),
            $password,
            $ip,
            fn (int $userId) => $this->customers->findActiveByUserId($userId),
        );
    }

    /**
     * Cria a conta do cliente. Se ele já comprou como visitante com o mesmo e-mail,
     * a conta é ligada ao cadastro existente (mantém o histórico de pedidos).
     *
     * @return array{customer_id:int,user_id:int,name:string,email:string}
     * @throws ValidationException|TooManyAttemptsException
     */
    public function registerCustomer(string $name, string $email, #[\SensitiveParameter] string $password, string $ip): array
    {
        $email = self::normalizeEmail($email);
        [$max, $window] = $this->config->get('security.rate_limits.register');
        $key = 'register:ip:' . $ip;

        $this->limiter->ensureNotBlocked($key);
        $this->limiter->hit($key, $max, $window);

        return $this->db->transaction(function () use ($name, $email, $password): array {
            if ($this->users->emailExists($email)) {
                throw new ValidationException(
                    ['email' => 'Este e-mail já possui cadastro. Entre com sua senha ou recupere o acesso.'],
                    ['name' => $name, 'email' => $email]
                );
            }

            $userId = $this->users->create($email, $this->hasher->hash($password), UserType::Customer);

            // Compras feitas sem conta NÃO são vinculadas aqui: sem confirmação do e-mail,
            // qualquer pessoa poderia se cadastrar com o e-mail de outra e ver os pedidos dela
            // (endereço, CPF). Esses pedidos seguem acessíveis pelo link privado de cada um.
            $customerId = $this->customers->create($userId, $name, $email);

            return ['customer_id' => $customerId, 'user_id' => $userId, 'name' => $name, 'email' => $email];
        });
    }

    /**
     * Usado pelo comando bin/create-admin.php.
     *
     * @throws ValidationException
     */
    public function createAdmin(string $name, string $email, #[\SensitiveParameter] string $password, AdminRole $role): int
    {
        $email = self::normalizeEmail($email);

        return $this->db->transaction(function () use ($name, $email, $password, $role): int {
            if ($this->users->emailExists($email)) {
                throw new ValidationException(['email' => 'Já existe um usuário com este e-mail.']);
            }
            $userId = $this->users->create($email, $this->hasher->hash($password), UserType::Admin);
            $adminId = $this->admins->create($userId, $name, $role);

            $this->audit->record(AuditService::CREATE, 'admin', $adminId, null, [
                'name' => $name, 'email' => $email, 'role' => $role->value,
            ]);

            return $adminId;
        });
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Fluxo comum de login com limite de tentativas por (e-mail + IP) e por IP.
     *
     * @param callable(int): ?array $profileLoader carrega o perfil ativo (admin/cliente)
     * @return array<string, mixed>
     */
    private function attempt(UserType $type, string $email, #[\SensitiveParameter] string $password, string $ip, callable $profileLoader): array
    {
        [$max, $window] = $this->config->get('security.rate_limits.login');
        [$maxIp, $windowIp] = $this->config->get('security.rate_limits.login_ip');
        $accountKey = 'login:' . $type->value . ':' . $this->hashKey($email . '|' . $ip);
        $ipKey = 'login_ip:' . $ip;

        $this->limiter->ensureNotBlocked($accountKey);
        $this->limiter->ensureNotBlocked($ipKey);

        $user = $this->users->findByEmail($email);
        // Sempre verifica um hash, mesmo sem usuário, para tempo de resposta constante.
        $passwordOk = $this->hasher->verify($password, $user['password_hash'] ?? $this->hasher->dummyHash());

        $profile = null;
        if ($passwordOk && $user !== null && $user['type'] === $type->value && $user['status'] === 'active') {
            $profile = $profileLoader((int) $user['id']);
        }

        if ($profile === null) {
            $this->limiter->hit($accountKey, $max, $window);
            $this->limiter->hit($ipKey, $maxIp, $windowIp);
            throw new AuthenticationException();
        }

        $this->limiter->clear($accountKey);
        if ($this->hasher->needsRehash($user['password_hash'])) {
            $this->users->updatePasswordHash((int) $user['id'], $this->hasher->hash($password));
        }
        $this->users->touchLastLogin((int) $user['id']);

        return $profile;
    }

    /** Não guarda e-mails em claro na tabela rate_limits. */
    private function hashKey(string $value): string
    {
        $key = (string) $this->config->get('app.key', '');

        return $key !== '' ? hash_hmac('sha256', $value, $key) : hash('sha256', $value);
    }
}
