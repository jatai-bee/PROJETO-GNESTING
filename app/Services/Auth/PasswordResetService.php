<?php

declare(strict_types=1);

namespace GNesting\Services\Auth;

use GNesting\Core\Config;
use GNesting\Core\Database;
use GNesting\Core\Logger;
use GNesting\Core\View;
use GNesting\Enums\UserType;
use GNesting\Repositories\AdminRepository;
use GNesting\Repositories\PasswordResetRepository;
use GNesting\Repositories\UserRepository;
use GNesting\Services\AuditService;
use GNesting\Services\Mail\Mailer;
use GNesting\Services\RateLimiter;

/**
 * "Esqueci minha senha" para clientes e administradores (docs/05 §4).
 *
 * - Token de 32 bytes aleatórios; o banco guarda só o SHA-256. Vale 60 minutos e uma única vez.
 * - A resposta ao pedido é sempre a mesma, exista ou não a conta (não revela e-mails cadastrados).
 * - Limites: 3 pedidos/hora por e-mail e 10/hora por IP.
 * - Trocar a senha invalida os outros tokens e derruba as sessões abertas (Auth compara o "carimbo" da senha).
 */
final class PasswordResetService
{
    public const TTL_MINUTES = 60;

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly AdminRepository $admins,
        private readonly PasswordResetRepository $resets,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly Mailer $mailer,
        private readonly View $view,
        private readonly AuditService $audit,
        private readonly Logger $logger,
        private readonly Config $config,
    ) {
    }

    /**
     * Envia o link por e-mail se houver conta ativa desse tipo. Não informa ao chamador se havia.
     *
     * @throws TooManyAttemptsException
     */
    public function request(string $email, UserType $type, string $ip): void
    {
        $email = AuthService::normalizeEmail($email);
        [$max, $window] = $this->config->get('security.rate_limits.password_reset');
        [$maxIp, $windowIp] = $this->config->get('security.rate_limits.password_reset_ip');
        $emailKey = 'reset:' . $this->hashKey($email);
        $ipKey = 'reset_ip:' . $ip;

        $this->limiter->ensureNotBlocked($emailKey);
        $this->limiter->ensureNotBlocked($ipKey);
        $this->limiter->hit($emailKey, $max, $window);
        $this->limiter->hit($ipKey, $maxIp, $windowIp);

        $user = $this->users->findByEmail($email);
        if ($user === null || $user['type'] !== $type->value || $user['status'] !== 'active'
            || ($type === UserType::Admin && $this->admins->findActiveByUserId((int) $user['id']) === null)) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $this->db->transaction(function () use ($user, $token): void {
            $this->resets->invalidateForUser((int) $user['id']);
            $this->resets->create((int) $user['id'], hash('sha256', $token), now_utc('+' . self::TTL_MINUTES . ' minutes'));
        });

        $prefix = $type === UserType::Admin ? '/admin' : '';
        $body = trim($this->view->render('emails/password_reset', [
            'link' => absolute_url($prefix . '/redefinir-senha/' . $token),
            'minutes' => self::TTL_MINUTES,
            'admin' => $type === UserType::Admin,
        ]));
        if (!$this->mailer->send($email, 'Redefinição de senha — G-Nesting', $body)) {
            $this->logger->error('Falha ao enviar e-mail de redefinição de senha', ['user_id' => $user['id']]);
        }
    }

    /** Token válido (formato, não usado, no prazo, conta ativa do tipo certo)? */
    public function isValid(string $token, UserType $type): bool
    {
        return $this->validRow($token, $type, false) !== null;
    }

    /**
     * Troca a senha e consome o token. Também confirma o e-mail: só quem recebeu o link chega aqui.
     *
     * @throws InvalidResetTokenException
     */
    public function reset(string $token, UserType $type, #[\SensitiveParameter] string $password): void
    {
        $this->db->transaction(function () use ($token, $type, $password): void {
            $row = $this->validRow($token, $type, true) ?? throw new InvalidResetTokenException();

            $userId = (int) $row['user_id'];
            $this->users->updatePasswordHash($userId, $this->hasher->hash($password));
            $this->users->markEmailVerified($userId);
            $this->resets->markUsed((int) $row['id']);
            $this->resets->invalidateForUser($userId);

            $this->audit->record(AuditService::PASSWORD_RESET, 'user', $userId, userId: $userId);
        });
    }

    /** @return array<string, mixed>|null */
    private function validRow(string $token, UserType $type, bool $forUpdate): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = $this->resets->findByHash(hash('sha256', $token), $forUpdate);
        if ($row === null || $row['used_at'] !== null || $row['expires_at'] <= now_utc()
            || $row['type'] !== $type->value || $row['status'] !== 'active') {
            return null;
        }

        return $row;
    }

    /** O e-mail não fica em claro na tabela rate_limits. */
    private function hashKey(string $value): string
    {
        $key = (string) $this->config->get('app.key', '');

        return $key !== '' ? hash_hmac('sha256', $value, $key) : hash('sha256', $value);
    }
}
