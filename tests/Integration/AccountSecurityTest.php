<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\Container;
use GNesting\Enums\AdminRole;
use GNesting\Services\OrderStatusService;

/** Etapa 11: recuperação de senha, sessões após troca de senha e direitos do titular (LGPD). */
final class AccountSecurityTest extends HttpTestCase
{
    private const SENT = 'Se houver uma conta com este e-mail';

    /** Link enviado no último e-mail para $email. */
    private function resetLinkFor(string $email): string
    {
        $mails = array_values(array_filter($this->mail->sent(), fn (array $m) => $m['to'] === $email));
        self::assertNotEmpty($mails, "E-mail para {$email}");
        preg_match('#https?://[^/\s]+(/(?:admin/)?redefinir-senha/[a-f0-9]{64})#', end($mails)['body'], $m);

        return $m[1] ?? self::fail('Link não encontrado no e-mail');
    }

    // ---- Recuperação de senha --------------------------------------------------------

    public function testCustomerResetsPasswordThroughEmailedLink(): void
    {
        $this->registerCustomer('cli@conta.test', 'senha-antiga-1');
        $this->post('/sair');

        $this->get('/recuperar-senha');
        $this->post('/recuperar-senha', ['email' => ' CLI@conta.test ']);
        self::assertStringContainsString(self::SENT, $this->get('/entrar')->body());
        $link = $this->resetLinkFor('cli@conta.test');
        self::assertSame(64, strlen(basename($link)));
        self::assertNull($this->fetchValue('SELECT 1 FROM password_resets WHERE token_hash = :t', ['t' => basename($link)]) ?: null, 'Banco guarda só o hash');

        $page = $this->get($link);
        self::assertSame(200, $page->status());
        self::assertSame('no-referrer', $page->header('Referrer-Policy'));

        $this->post($link, ['password' => 'curta', 'password_confirmation' => 'curta']);
        self::assertStringContainsString('pelo menos', $this->get($link)->body(), 'Validação volta ao formulário');

        self::assertSame('/entrar', $this->post($link, ['password' => 'senha-nova-123', 'password_confirmation' => 'senha-nova-123'])->header('Location'));
        self::assertNotNull($this->fetchValue("SELECT email_verified_at FROM users WHERE email = 'cli@conta.test'"), 'E-mail confirmado');

        // Uso único
        self::assertSame('/recuperar-senha', $this->get($link)->header('Location'));
        self::assertSame('/recuperar-senha', $this->post($link, ['password' => 'outra-senha-99', 'password_confirmation' => 'outra-senha-99'])->header('Location'));

        $this->post('/entrar', ['email' => 'cli@conta.test', 'password' => 'senha-antiga-1']);
        self::assertSame('/entrar', $this->get('/conta')->header('Location'), 'Senha antiga não vale mais');
        self::assertSame('/conta', $this->post('/entrar', ['email' => 'cli@conta.test', 'password' => 'senha-nova-123'])->header('Location'));
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'password_reset'"));
    }

    public function testUnknownEmailsGetTheSameAnswerAndNoEmail(): void
    {
        $this->loginAdmin(AdminRole::Manager);
        $this->newBrowser();
        foreach (['ninguem@conta.test', 'manager@seguranca.test'] as $email) { // sem conta / conta de outro tipo
            $this->get('/recuperar-senha');
            self::assertSame('/entrar', $this->post('/recuperar-senha', ['email' => $email])->header('Location'));
            self::assertStringContainsString(self::SENT, $this->get('/entrar')->body());
        }
        self::assertSame([], $this->mail->sent());
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM password_resets'));
    }

    public function testExpiredOrReplacedTokensAreRefused(): void
    {
        $this->registerCustomer('exp@conta.test');
        $this->newBrowser();
        $this->post('/recuperar-senha', ['email' => 'exp@conta.test']);
        $first = $this->resetLinkFor('exp@conta.test');
        $this->post('/recuperar-senha', ['email' => 'exp@conta.test']);
        $second = $this->resetLinkFor('exp@conta.test');

        self::assertNotSame($first, $second);
        self::assertSame('/recuperar-senha', $this->get($first)->header('Location'), 'Pedido novo invalida o anterior');
        self::assertSame(200, $this->get($second)->status());

        $this->db->pdo()->exec('UPDATE password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        self::assertSame('/recuperar-senha', $this->get($second)->header('Location'), 'Vencido');
        self::assertSame(404, $this->get('/redefinir-senha/' . str_repeat('z', 64))->status(), 'Formato inválido nem casa com a rota');
    }

    public function testResetRequestsAreRateLimited(): void
    {
        $this->registerCustomer('limite@conta.test');
        $this->newBrowser();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/recuperar-senha', ['email' => 'limite@conta.test']);
        }
        $this->post('/recuperar-senha', ['email' => 'limite@conta.test']);
        self::assertStringContainsString('Muitas tentativas', $this->get('/recuperar-senha')->body());
        self::assertCount(3, $this->mail->sent());
    }

    public function testAdminResetUsesItsOwnLinkAndIgnoresCustomers(): void
    {
        $this->registerCustomer('cliente@conta.test');
        $email = $this->loginAdmin(AdminRole::Support);
        $this->newBrowser();

        $this->get('/admin/recuperar-senha');
        $this->post('/admin/recuperar-senha', ['email' => 'cliente@conta.test']);
        self::assertSame([], $this->mail->sent(), 'Cliente não recebe link do painel');

        $this->post('/admin/recuperar-senha', ['email' => $email]);
        $link = $this->resetLinkFor($email);
        self::assertStringStartsWith('/admin/redefinir-senha/', $link);
        self::assertSame('/recuperar-senha', $this->get(substr($link, 6))->header('Location'), 'Token de admin não vale na loja');

        $this->post($link, ['password' => 'curta-11ch', 'password_confirmation' => 'curta-11ch']);
        self::assertStringContainsString('pelo menos 12', $this->get($link)->body(), 'Mínimo do painel');
        self::assertSame('/admin/login', $this->post($link, ['password' => 'nova-senha-do-painel', 'password_confirmation' => 'nova-senha-do-painel'])->header('Location'));
        self::assertSame('/admin', $this->post('/admin/login', ['email' => $email, 'password' => 'nova-senha-do-painel'])->header('Location'));
    }

    public function testPasswordChangeEndsOtherSessions(): void
    {
        // Cliente: aparelho A logado; senha trocada pelo link em B
        $this->registerCustomer('sessao@conta.test');
        $deviceA = $this->container;
        $this->newBrowser();
        $this->post('/recuperar-senha', ['email' => 'sessao@conta.test']);
        $link = $this->resetLinkFor('sessao@conta.test');
        $this->post($link, ['password' => 'senha-trocada-1', 'password_confirmation' => 'senha-trocada-1']);
        $this->container = $deviceA;
        self::assertSame('/entrar', $this->get('/conta')->header('Location'));

        // Admin: o proprietário troca a senha de um gestor logado → a sessão do gestor cai;
        // o proprietário troca a própria → a sessão dele continua
        $this->loginAdmin(AdminRole::Manager);
        $manager = $this->container;
        $this->loginAdmin(AdminRole::Owner);
        $owner = $this->container;
        $managerId = (int) $this->fetchValue("SELECT a.id FROM admins a JOIN users u ON u.id = a.user_id WHERE u.email = 'manager@seguranca.test'");
        $ownerId = (int) $this->fetchValue("SELECT a.id FROM admins a JOIN users u ON u.id = a.user_id WHERE u.email = 'owner@seguranca.test'");
        $this->post("/admin/usuarios/{$managerId}/senha", ['password' => 'gestor-senha-nova', 'password_confirmation' => 'gestor-senha-nova']);
        $this->post("/admin/usuarios/{$ownerId}/senha", ['password' => 'dono-senha-nova-1', 'password_confirmation' => 'dono-senha-nova-1']);
        self::assertSame(200, $this->get('/admin')->status(), 'Proprietário segue logado');

        $this->container = $manager;
        self::assertSame('/admin/login', $this->get('/admin')->header('Location'), 'Gestor desconectado');
        $this->container = $owner;
    }

    // ---- LGPD ----------------------------------------------------------------------------

    /** Pedido pago, entregue e com data de $yearsAgo anos atrás. @return array<string, mixed> */
    private function deliveredOrder(string $email, int $yearsAgo = 0): array
    {
        $this->newBrowser();
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        $this->post('/checkout', $this->checkoutForm(['email' => $email]));
        $order = $this->lastOrder();
        $this->db->pdo()->exec("UPDATE orders SET status = 'delivered', payment_status = 'paid',
            placed_at = UTC_TIMESTAMP() - INTERVAL {$yearsAgo} YEAR WHERE id = {$order['id']}");

        return $this->lastOrder();
    }

    public function testOwnerExportsCustomerData(): void
    {
        $order = $this->deliveredOrder('titular@conta.test');
        $customerId = (int) $order['customer_id'];

        $this->loginAdmin(AdminRole::Manager);
        self::assertSame(403, $this->post("/admin/clientes/{$customerId}/exportar")->status(), 'Só o proprietário');
        self::assertStringNotContainsString('Dados pessoais (LGPD)', $this->get("/admin/clientes/{$customerId}")->body());

        $this->loginAdmin(AdminRole::Owner);
        self::assertStringContainsString('Dados pessoais (LGPD)', $this->get("/admin/clientes/{$customerId}")->body());
        $response = $this->post("/admin/clientes/{$customerId}/exportar");
        self::assertStringContainsString('attachment; filename="dados-cliente-' . $customerId . '.json"', (string) $response->header('Content-Disposition'));
        $data = json_decode($response->body(), true);
        self::assertSame('titular@conta.test', $data['cadastro']['email']);
        self::assertSame('52998224725', $data['cadastro']['cpf']);
        self::assertSame($order['number'], $data['pedidos'][0]['numero']);
        self::assertSame('Av. Paulista', $data['pedidos'][0]['entrega']['rua']);
        self::assertSame('Relógio Geométrico G-Nesting', $data['pedidos'][0]['itens'][0]['produto']);

        $audit = $this->db->pdo()->query("SELECT new_values FROM audit_logs WHERE action = 'export'")->fetchColumn();
        self::assertStringNotContainsString('titular@conta.test', (string) $audit, 'Auditoria sem dado pessoal');
    }

    public function testAnonymizationKeepsRecentOrdersAndErasesTheRest(): void
    {
        $this->registerCustomer('apagar@conta.test');
        $customerId = (int) $this->fetchValue("SELECT id FROM customers WHERE email = 'apagar@conta.test'");
        $userId = (int) $this->fetchValue("SELECT id FROM users WHERE email = 'apagar@conta.test'");
        $this->db->pdo()->exec("INSERT INTO addresses (customer_id, recipient_name, zip_code, street, number, district, city, state)
                                VALUES ({$customerId}, 'Ana', '01310100', 'Av. Paulista', '1000', 'Bela Vista', 'São Paulo', 'SP')");
        $recent = $this->deliveredOrder('apagar@conta.test');
        $old = $this->deliveredOrder('apagar@conta.test', 6);
        $this->db->pdo()->exec("UPDATE orders SET customer_id = {$customerId} WHERE id IN ({$recent['id']}, {$old['id']})");
        $open = $this->deliveredOrder('apagar@conta.test');
        $this->db->pdo()->exec("UPDATE orders SET customer_id = {$customerId}, status = 'in_production' WHERE id = {$open['id']}");

        $this->loginAdmin(AdminRole::Owner);
        $this->post("/admin/clientes/{$customerId}/anonimizar", ['confirm' => 'anonimizar']);
        self::assertStringContainsString('digite ANONIMIZAR', $this->get("/admin/clientes/{$customerId}")->body());

        $this->post("/admin/clientes/{$customerId}/anonimizar", ['confirm' => 'ANONIMIZAR']);
        self::assertStringContainsString('pedidos em andamento', $this->get("/admin/clientes/{$customerId}")->body());

        $this->db->pdo()->exec("UPDATE orders SET status = 'delivered' WHERE id = {$open['id']}");
        $this->post("/admin/clientes/{$customerId}/anonimizar", ['confirm' => 'ANONIMIZAR']);
        self::assertStringContainsString('2 pedido(s) mantido(s)', $this->get("/admin/clientes/{$customerId}")->body());

        $customer = $this->db->pdo()->query("SELECT * FROM customers WHERE id = {$customerId}")->fetch();
        self::assertSame('Cliente anonimizado', $customer['name']);
        self::assertStringEndsWith('@anonimizado.invalid', $customer['email']);
        self::assertNull($customer['cpf']);
        self::assertNull($customer['user_id']);
        self::assertNotNull($customer['anonymized_at']);
        self::assertFalse((bool) $this->fetchValue("SELECT 1 FROM users WHERE id = {$userId}"), 'Login apagado');
        self::assertSame(0, (int) $this->fetchValue("SELECT COUNT(*) FROM addresses WHERE customer_id = {$customerId}"));

        $orders = $this->db->pdo()->query("SELECT id, customer_name, customer_cpf, ship_street FROM orders WHERE customer_id = {$customerId}")->fetchAll(\PDO::FETCH_UNIQUE);
        self::assertSame('Ana Souza', $orders[$recent['id']]['customer_name'], 'Pedido recente mantido (obrigação fiscal)');
        self::assertSame('Cliente anonimizado', $orders[$old['id']]['customer_name'], 'Pedido antigo anonimizado');
        self::assertNull($orders[$old['id']]['customer_cpf']);
        self::assertSame('—', $orders[$old['id']]['ship_street']);

        self::assertSame('/entrar', $this->loginAsDeleted(), 'Não consegue mais entrar');
        $this->loginAdmin(AdminRole::Owner);
        $this->post("/admin/clientes/{$customerId}/exportar");
        self::assertStringContainsString('foi anonimizado', $this->get("/admin/clientes/{$customerId}")->body());

        $audit = (string) $this->fetchValue("SELECT new_values FROM audit_logs WHERE action = 'anonymize'");
        self::assertStringContainsString('"orders_kept": 2', $audit);
        self::assertStringNotContainsString('apagar@conta.test', $audit);
    }

    private function loginAsDeleted(): string
    {
        $this->newBrowser();
        $this->get('/entrar');

        return (string) $this->post('/entrar', ['email' => 'apagar@conta.test', 'password' => 'senha-do-cliente'])->header('Location');
    }
}
