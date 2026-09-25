<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\Bootstrap;
use GNesting\Core\Csrf;
use GNesting\Core\Database;
use GNesting\Core\Kernel;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Enums\AdminRole;
use GNesting\Enums\OrderStatus;
use GNesting\Services\AdminUserService;
use GNesting\Services\Mail\LogMailer;
use GNesting\Services\Mail\Mailer;
use GNesting\Services\OrderLink;
use GNesting\Services\OrderStatusService;
use GNesting\Services\Payment\GatewayPayment;
use GNesting\Services\Payment\PaymentGateway;
use GNesting\Services\Payment\SimulatedGateway;
use GNesting\Tests\Support\TestFiles;

/**
 * Etapa 8: gestão de pedidos no painel — transições por papel, efeitos (vendas, estoque,
 * remessa, estorno), notas e mensagens, e-mails ao cliente, clientes e indicadores.
 */
final class OrderManagementTest extends IntegrationTestCase
{
    private ?string $cartCookie = null;
    private LogMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new LogMailer(TestFiles::tempDir('gn-mail'));
        $this->container->set(Mailer::class, fn () => $this->mailer);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestFiles::cleanup();
    }

    // ---- infraestrutura -------------------------------------------------------------

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->send(new Request('GET', $path, $query, [], $this->cookies(), ['REMOTE_ADDR' => '192.0.2.80']));
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body = []): Response
    {
        return $this->send(new Request('POST', $path, [], $body + ['_token' => $this->container->get(Csrf::class)->token()], $this->cookies(), ['REMOTE_ADDR' => '192.0.2.80']));
    }

    private function send(Request $request): Response
    {
        $response = $this->container->get(Kernel::class)->handle($request);
        if (isset($response->cookies()['gn_cart'])) {
            $this->cartCookie = $response->cookies()['gn_cart']['value'];
        }

        return $response;
    }

    /** @return array<string, string> */
    private function cookies(): array
    {
        return $this->cartCookie === null ? [] : ['gn_cart' => $this->cartCookie];
    }

    /** Sessão nova (outro navegador / outra pessoa). */
    private function newBrowser(): void
    {
        $this->cartCookie = null;
        $container = Bootstrap::createContainer(dirname(__DIR__, 2));
        $container->instance(Database::class, $this->db);
        $container->set(Mailer::class, fn () => $this->mailer);
        $this->container = $container;
    }

    private function loginAs(AdminRole $role): void
    {
        $this->newBrowser();
        $email = $role->value . '@pedidos.test';
        if (!$this->fetchValue('SELECT 1 FROM users WHERE email = :e', ['e' => $email])) {
            $this->container->get(AdminUserService::class)->create('Equipe ' . $role->label(), $email, $role, 'senha-muito-segura');
        }
        $this->get('/admin/login');
        self::assertSame('/admin', $this->post('/admin/login', ['email' => $email, 'password' => 'senha-muito-segura'])->header('Location'));
    }

    /** Pedido pago (fila de produção), com um item de pronta entrega. @return array<string, mixed> */
    private function paidOrder(): array
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO products (category_id, name, slug, is_active, production_lead_days, published_at)
                    SELECT id, 'Vaso Pronto', 'vaso-pronto', 1, 2, UTC_TIMESTAMP() FROM categories WHERE slug = 'decoracao'");
        $productId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO product_variants (product_id, sku, price_cents, is_default) VALUES ({$productId}, 'VASO-01', 10000, 1)");
        $variantId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO inventory (variant_id, stock_mode, quantity_on_hand) VALUES ({$variantId}, 'stock', 5)");

        $this->newBrowser();
        $this->post('/carrinho/itens', ['variant_id' => (string) $variantId, 'quantity' => '2']);
        $placed = $this->post('/checkout', [
            'name' => 'Ana Souza', 'email' => 'ana@cliente.test', 'cpf' => '529.982.247-25', 'phone' => '(71) 99999-8888',
            'zip_code' => '40140-110', 'street' => 'Rua das Flores', 'number' => '100', 'complement' => '', 'district' => 'Ondina',
            'city' => 'Salvador', 'state' => 'BA', 'recipient_name' => '', 'shipping_code' => 'economico', 'quoted_zip' => '40140110',
        ]);
        $this->post((string) $placed->header('Location'), ['resultado' => 'aprovar']);

        return $this->order();
    }

    /** @return array{to: string, subject: string, body: string} */
    private function lastMail(): array
    {
        $sent = $this->mailer->sent();

        return $sent[array_key_last($sent)];
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return $this->db->pdo()->query('SELECT * FROM orders ORDER BY id DESC LIMIT 1')->fetch();
    }

    private function stock(string $sku): array
    {
        return $this->db->pdo()->query("SELECT i.quantity_on_hand, i.quantity_reserved FROM inventory i JOIN product_variants v ON v.id = i.variant_id WHERE v.sku = '{$sku}'")->fetch();
    }

    // ---- matriz de papéis --------------------------------------------------------------

    public function testRoleMatrix(): void
    {
        $values = static fn (array $list): array => array_map(static fn (OrderStatus $s): string => $s->value, $list);

        self::assertSame(['in_production', 'cancelled'], $values(OrderStatusService::targetsFor(AdminRole::Manager, OrderStatus::ProductionPending)));
        self::assertSame(['in_production'], $values(OrderStatusService::targetsFor(AdminRole::Production, OrderStatus::ProductionPending)));
        self::assertSame(['packaging', 'in_production'], $values(OrderStatusService::targetsFor(AdminRole::Production, OrderStatus::QualityControl)), 'Retrabalho');
        self::assertSame([], OrderStatusService::targetsFor(AdminRole::Production, OrderStatus::ReadyToShip), 'Envio é do gestor nesta etapa');
        self::assertSame([], OrderStatusService::targetsFor(AdminRole::Support, OrderStatus::ProductionPending));
        self::assertSame(['cancelled'], $values(OrderStatusService::targetsFor(AdminRole::Owner, OrderStatus::AwaitingPayment)), '"Pago" nunca é manual');
        self::assertSame([], OrderStatusService::targetsFor(AdminRole::Owner, OrderStatus::Delivered));
    }

    // ---- fluxo completo -------------------------------------------------------------------

    public function testPaidOrderGoesThroughProductionShippingAndDelivery(): void
    {
        $order = $this->paidOrder();
        $id = (int) $order['id'];
        self::assertSame('production_pending', $order['status']);
        self::assertSame(2, (int) $this->fetchValue("SELECT sales_count FROM products WHERE slug = 'vaso-pronto'"), '"Mais vendidos" conta no pagamento');
        self::assertSame(['quantity_on_hand' => 3, 'quantity_reserved' => 0], array_map('intval', $this->stock('VASO-01')));
        self::assertStringContainsString('Pagamento aprovado', $this->lastMail()['subject']);

        // Produção avança as etapas (inclui retrabalho no CQ)
        $this->loginAs(AdminRole::Production);
        self::assertStringContainsString('Mover para: Em produção', $this->get("/admin/pedidos/{$id}")->body());
        foreach (['in_production', 'finishing', 'quality_control', 'in_production', 'finishing', 'quality_control', 'packaging', 'ready_to_ship'] as $step) {
            $this->post("/admin/pedidos/{$id}/status", ['target' => $step]);
            self::assertSame($step, $this->order()['status'], $step);
        }
        self::assertSame(403, $this->post("/admin/pedidos/{$id}/status", ['target' => 'shipped'])->status(), 'Produção não despacha');
        self::assertSame(403, $this->post("/admin/pedidos/{$id}/cancelar", ['reason' => 'x'])->status());
        self::assertSame(403, $this->post("/admin/pedidos/{$id}/mensagem", ['body' => 'oi'])->status());
        self::assertStringContainsString('está em produção', implode("\n", array_column($this->mailer->sent(), 'subject')));

        // Gestor despacha com rastreio; link malicioso é recusado
        $this->loginAs(AdminRole::Manager);
        $this->post("/admin/pedidos/{$id}/status", ['target' => 'shipped', 'tracking_code' => 'aa123', 'tracking_url' => 'javascript:alert(1)']);
        self::assertSame('ready_to_ship', $this->order()['status']);
        $this->post("/admin/pedidos/{$id}/status", ['target' => 'shipped', 'carrier' => 'Correios', 'tracking_code' => 'aa123456789br',
            'tracking_url' => 'https://rastreamento.correios.com.br/app/index.php']);
        self::assertSame('shipped', $this->order()['status']);
        $shipment = $this->db->pdo()->query("SELECT * FROM shipments WHERE order_id = {$id}")->fetch();
        self::assertSame('AA123456789BR', $shipment['tracking_code']);
        $shippedMail = $this->lastMail();
        self::assertStringContainsString('enviado', $shippedMail['subject']);
        self::assertStringContainsString('AA123456789BR', $shippedMail['body']);

        // Link do e-mail (chave derivada) abre a página do cliente com o rastreio
        preg_match('#/pedido/(GN-\d{4}-\d{6})/confirmacao\?chave=([a-f0-9]{48})#', $shippedMail['body'], $link);
        $this->newBrowser();
        $page = $this->get("/pedido/{$link[1]}/confirmacao", ['chave' => $link[2]])->body();
        self::assertStringContainsString('AA123456789BR', $page);
        self::assertStringContainsString('https://rastreamento.correios.com.br/app/index.php', $page);

        $this->loginAs(AdminRole::Manager);
        $this->post("/admin/pedidos/{$id}/status", ['target' => 'delivered']);
        self::assertSame('delivered', $this->order()['status']);
        self::assertNotNull($this->fetchValue("SELECT delivered_at FROM shipments WHERE order_id = {$id}"));
        self::assertSame(403, $this->post("/admin/pedidos/{$id}/status", ['target' => 'in_production'])->status(), 'Entregue é final');

        // Histórico completo, com autor e origem
        self::assertSame(13, (int) $this->fetchValue("SELECT COUNT(*) FROM order_status_history WHERE order_id = {$id}"));
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'order' AND entity_id = {$id} AND new_values LIKE '%delivered%'"));
    }

    public function testCancellingPaidOrderRefundsReturnsStockAndUndoesSales(): void
    {
        $order = $this->paidOrder();
        $id = (int) $order['id'];
        $this->loginAs(AdminRole::Manager);

        $this->post("/admin/pedidos/{$id}/cancelar", ['reason' => '', 'refund' => 'gateway']);
        self::assertSame('production_pending', $this->order()['status'], 'Motivo obrigatório');

        $this->post("/admin/pedidos/{$id}/cancelar", ['reason' => 'Cliente desistiu antes da produção', 'refund' => 'gateway']);
        $order = $this->order();
        self::assertSame('cancelled', $order['status']);
        self::assertSame('refunded', $order['payment_status']);
        self::assertSame('refunded', $this->fetchValue("SELECT status FROM payments WHERE order_id = {$id} AND provider_payment_id IS NOT NULL"));
        self::assertSame(['quantity_on_hand' => 5, 'quantity_reserved' => 0], array_map('intval', $this->stock('VASO-01')), 'Estoque devolvido');
        self::assertSame(0, (int) $this->fetchValue("SELECT sales_count FROM products WHERE slug = 'vaso-pronto'"));

        $mail = $this->lastMail();
        self::assertStringContainsString('cancelado', $mail['subject']);
        self::assertStringContainsString('Cliente desistiu', $mail['body']);
        self::assertStringContainsString('estornado', $mail['body']);
    }

    public function testFailedRefundKeepsOrderUntouched(): void
    {
        $order = $this->paidOrder();
        $this->loginAs(AdminRole::Manager);
        $this->container->set(PaymentGateway::class, fn () => new class extends \stdClass implements PaymentGateway {
            public function name(): string { return 'simulado'; }
            public function createCheckout(array $order, array $items, array $urls): array { return ['reference' => 'x', 'url' => 'x']; }
            public function refund(string $paymentId): void { throw new \RuntimeException('HTTP 500'); }
            public function fetchPayment(string $paymentId): ?GatewayPayment { return null; }
            public function parseWebhook(Request $request): array { return []; }
        });

        $this->post("/admin/pedidos/{$order['id']}/cancelar", ['reason' => 'Teste', 'refund' => 'gateway']);
        self::assertSame('production_pending', $this->order()['status']);
        self::assertStringContainsString('não confirmou o estorno', $this->get("/admin/pedidos/{$order['id']}")->body());

        // Estorno feito por fora: registrado
        $this->post("/admin/pedidos/{$order['id']}/cancelar", ['reason' => 'Teste', 'refund' => 'manual']);
        self::assertSame('cancelled', $this->order()['status']);
        self::assertStringContainsString('fora da loja', (string) $this->fetchValue("SELECT GROUP_CONCAT(body) FROM order_notes WHERE order_id = {$order['id']}"));
    }

    // ---- notas, mensagens, link ----------------------------------------------------------

    public function testNotesMessagesAndLinkBySupport(): void
    {
        $order = $this->paidOrder();
        $id = (int) $order['id'];

        $this->loginAs(AdminRole::Support);
        $page = $this->get("/admin/pedidos/{$id}")->body();
        self::assertStringNotContainsString('Mover para', $page, 'Atendimento não move o pedido');
        self::assertStringContainsString('***.982.247-**', $page, 'CPF mascarado para o atendimento');
        self::assertStringContainsString('https://wa.me/5571999998888?text=', $page);

        $this->post("/admin/pedidos/{$id}/nota", ['body' => 'Cliente ligou pedindo urgência']);
        $this->post("/admin/pedidos/{$id}/mensagem", ['body' => 'Olá! Seu pedido entra na produção amanhã.']);
        self::assertSame(403, $this->post("/admin/pedidos/{$id}/status", ['target' => 'in_production'])->status());

        $message = $this->lastMail();
        self::assertStringContainsString('Mensagem sobre o pedido', $message['subject']);
        self::assertStringContainsString('entra na produção amanhã', $message['body']);
        self::assertNotNull($this->fetchValue("SELECT emailed_at FROM order_notes WHERE order_id = {$id} AND visibility = 'customer'"));

        $this->post("/admin/pedidos/{$id}/reenviar-link");
        self::assertStringContainsString($this->container->get(OrderLink::class)->url($order['number']), $this->lastMail()['body']);

        // Cliente vê a mensagem, nunca a nota interna
        $this->newBrowser();
        $customerPage = $this->get('/pedido/' . $order['number'] . '/confirmacao', ['chave' => $this->container->get(OrderLink::class)->token($order['number'])])->body();
        self::assertStringContainsString('entra na produção amanhã', $customerPage);
        self::assertStringNotContainsString('pedindo urgência', $customerPage);

        // Produção pode anotar, não mandar mensagem
        $this->loginAs(AdminRole::Production);
        $this->post("/admin/pedidos/{$id}/nota", ['body' => 'Chapa separada']);
        self::assertSame(2, (int) $this->fetchValue("SELECT COUNT(*) FROM order_notes WHERE order_id = {$id} AND visibility = 'internal'"));
    }

    // ---- listagem, clientes, painel ---------------------------------------------------

    public function testListingFiltersCustomersAndDashboard(): void
    {
        $order = $this->paidOrder();

        $this->loginAs(AdminRole::Manager);
        $list = $this->get('/admin/pedidos', ['status' => 'production_pending'])->body();
        self::assertStringContainsString($order['number'], $list);
        self::assertStringNotContainsString($order['number'], $this->get('/admin/pedidos', ['status' => 'shipped'])->body());
        self::assertStringContainsString($order['number'], $this->get('/admin/pedidos', ['q' => '98224'])->body(), 'Busca por CPF');
        self::assertStringContainsString($order['number'], $this->get('/admin/pedidos', ['q' => 'ana@cliente'])->body());
        self::assertSame(200, $this->get('/admin/pedidos', ['status' => "x' OR 1=1", 'de' => 'ontem'])->status(), 'Filtros inválidos são ignorados');

        $customers = $this->get('/admin/clientes', ['q' => 'Ana'])->body();
        self::assertStringContainsString('Ana Souza', $customers);
        self::assertStringContainsString('529.982.247-25', $customers, 'Gestor vê CPF completo');
        $detail = $this->get('/admin/clientes/' . $order['customer_id'])->body();
        self::assertStringContainsString($order['number'], $detail);
        self::assertStringContainsString(money((int) $order['total_cents']), $detail, 'Total pago = total do pedido (itens + frete)');

        $dashboard = $this->get('/admin')->body();
        self::assertStringContainsString('Vendas no mês', $dashboard);
        self::assertStringContainsString(money((int) $order['total_cents']), $dashboard);

        $this->loginAs(AdminRole::Support);
        self::assertStringNotContainsString('Vendas no mês', $this->get('/admin')->body(), 'Faturamento só para gestão');
        self::assertStringContainsString('***.982.247-**', $this->get('/admin/clientes')->body());

        $this->loginAs(AdminRole::Production);
        self::assertSame(403, $this->get('/admin/clientes')->status());
        self::assertSame(200, $this->get('/admin/pedidos')->status());
    }
}
