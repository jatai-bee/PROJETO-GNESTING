<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\ValidationException;
use GNesting\Enums\AdminRole;
use GNesting\Repositories\CustomerRepository;
use GNesting\Services\Auth\AuthenticationException;
use GNesting\Services\Auth\AuthService;
use GNesting\Services\Auth\TooManyAttemptsException;

final class AuthServiceTest extends IntegrationTestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->container->get(AuthService::class);
    }

    public function testRegisterAndLoginCustomer(): void
    {
        $registered = $this->auth->registerCustomer('Maria Souza', ' Maria@Exemplo.com ', 'senha-segura-1', '10.0.0.1');
        self::assertSame('maria@exemplo.com', $registered['email']);

        $hash = (string) $this->fetchValue('SELECT password_hash FROM users WHERE id = :id', ['id' => $registered['user_id']]);
        self::assertStringStartsWith('$argon2id$', $hash);

        $logged = $this->auth->attemptCustomer('MARIA@exemplo.com', 'senha-segura-1', '10.0.0.1');
        self::assertSame($registered['customer_id'], $logged['customer_id']);
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->auth->registerCustomer('Maria', 'dup@exemplo.com', 'senha-segura-1', '10.0.0.2');

        $this->expectException(ValidationException::class);
        $this->auth->registerCustomer('Outra', 'DUP@exemplo.com', 'senha-segura-1', '10.0.0.2');
    }

    public function testRegistrationDoesNotTakeOverGuestPurchasesWithoutEmailVerification(): void
    {
        // Sem confirmar o e-mail, cadastrar-se com o e-mail de outra pessoa não pode dar acesso aos pedidos dela
        $guestId = $this->container->get(CustomerRepository::class)->create(null, 'Visitante', 'guest@exemplo.com');

        $registered = $this->auth->registerCustomer('Cliente Fiel', 'guest@exemplo.com', 'senha-segura-1', '10.0.0.3');

        self::assertNotSame($guestId, $registered['customer_id']);
        self::assertNull($this->fetchValue('SELECT user_id FROM customers WHERE id = :id', ['id' => $guestId]) ?: null);
    }

    public function testWrongPasswordAndUnknownEmailGiveSameError(): void
    {
        $this->auth->registerCustomer('Maria', 'same@exemplo.com', 'senha-segura-1', '10.0.0.4');

        $messages = [];
        foreach ([['same@exemplo.com', 'errada'], ['naoexiste@exemplo.com', 'qualquer']] as [$email, $password]) {
            try {
                $this->auth->attemptCustomer($email, $password, '10.0.0.4');
                self::fail('Deveria falhar');
            } catch (AuthenticationException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertSame($messages[0], $messages[1]);
    }

    public function testAccountIsBlockedAfterFiveFailures(): void
    {
        $this->auth->registerCustomer('Maria', 'lock@exemplo.com', 'senha-segura-1', '10.0.0.5');

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->auth->attemptCustomer('lock@exemplo.com', 'errada', '10.0.0.5');
            } catch (AuthenticationException) {
            }
        }

        $this->expectException(TooManyAttemptsException::class);
        $this->auth->attemptCustomer('lock@exemplo.com', 'senha-segura-1', '10.0.0.5');
    }

    public function testCustomerCannotLoginAsAdminAndViceVersa(): void
    {
        $this->auth->registerCustomer('Maria', 'cliente@exemplo.com', 'senha-segura-1', '10.0.0.6');
        $this->auth->createAdmin('Dono', 'dono@gnesting.test', 'senha-admin-segura', AdminRole::Owner);

        try {
            $this->auth->attemptAdmin('cliente@exemplo.com', 'senha-segura-1', '10.0.0.6');
            self::fail('Cliente não pode entrar no painel');
        } catch (AuthenticationException) {
        }

        $this->expectException(AuthenticationException::class);
        $this->auth->attemptCustomer('dono@gnesting.test', 'senha-admin-segura', '10.0.0.6');
    }

    public function testAdminLoginIsAuditedAndInactiveAdminIsRejected(): void
    {
        $adminId = $this->auth->createAdmin('Gestor', 'gestor@gnesting.test', 'senha-admin-segura', AdminRole::Manager);

        $admin = $this->auth->attemptAdmin('gestor@gnesting.test', 'senha-admin-segura', '10.0.0.7');
        self::assertSame('manager', $admin['role']);
        self::assertSame(1, (int) $this->fetchValue(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'login' AND entity_type = 'admin' AND entity_id = :id",
            ['id' => $adminId]
        ));

        $this->db->pdo()->exec("UPDATE admins SET is_active = 0 WHERE id = {$adminId}");
        try {
            $this->auth->attemptAdmin('gestor@gnesting.test', 'senha-admin-segura', '10.0.0.7');
            self::fail('Admin inativo não pode entrar');
        } catch (AuthenticationException) {
        }
        self::assertGreaterThan(0, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'login_failed'"));
    }

    public function testRateLimitKeysDoNotStoreEmailsInClear(): void
    {
        try {
            $this->auth->attemptCustomer('privado@exemplo.com', 'x', '10.0.0.8');
        } catch (AuthenticationException) {
        }

        self::assertSame(0, (int) $this->fetchValue("SELECT COUNT(*) FROM rate_limits WHERE bucket_key LIKE '%privado%'"));
    }
}
