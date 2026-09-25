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
use GNesting\Repositories\ProductionJobRepository;
use GNesting\Services\AdminUserService;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\Mail\LogMailer;
use GNesting\Services\Mail\Mailer;
use GNesting\Services\OrderStatusService;
use GNesting\Services\Production\ProductionPlanner;
use GNesting\Services\Production\ProductionService;
use GNesting\Tests\Support\TestFiles;

/** Etapa 9: fila de produção, previsão de carga, expedição e matéria-prima. */
final class ProductionQueueTest extends IntegrationTestCase
{
    private ?string $cartCookie = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->newBrowser();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestFiles::cleanup();
    }

    private function newBrowser(): void
    {
        $this->cartCookie = null;
        $container = Bootstrap::createContainer(dirname(__DIR__, 2));
        $container->instance(Database::class, $this->db);
        $container->set(Mailer::class, fn () => new LogMailer(TestFiles::tempDir('gn-mail')));
        $this->container = $container;
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->send(new Request('GET', $path, $query, [], $this->cartCookie ? ['gn_cart' => $this->cartCookie] : [], ['REMOTE_ADDR' => '192.0.2.90']));
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body = []): Response
    {
        return $this->send(new Request('POST', $path, [], $body + ['_token' => $this->container->get(Csrf::class)->token()],
            $this->cartCookie ? ['gn_cart' => $this->cartCookie] : [], ['REMOTE_ADDR' => '192.0.2.90']));
    }

    private function send(Request $request): Response
    {
        $response = $this->container->get(Kernel::class)->handle($request);
        if (isset($response->cookies()['gn_cart'])) {
            $this->cartCookie = $response->cookies()['gn_cart']['value'];
        }

        return $response;
    }

    private function loginAs(AdminRole $role): void
    {
        $this->newBrowser();
        $email = $role->value . '@fila.test';
        if (!$this->fetchValue('SELECT 1 FROM users WHERE email = :e', ['e' => $email])) {
            $this->container->get(AdminUserService::class)->create('Equipe ' . $role->label(), $email, $role, 'senha-muito-segura');
        }
        $this->get('/admin/login');
        $this->post('/admin/login', ['email' => $email, 'password' => 'senha-muito-segura']);
    }

    /** Produto com ficha (CNC 30 min de operador) e sem controle de estoque. @return int variante */
    private function product(string $slug, int $cncMinutes = 30): int
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO products (category_id, name, slug, is_active, production_lead_days, published_at)
                    SELECT id, 'Peça {$slug}', '{$slug}', 1, 3, UTC_TIMESTAMP() FROM categories WHERE slug = 'decoracao'");
        $productId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO product_variants (product_id, sku, price_cents, is_default) VALUES ({$productId}, UPPER('{$slug}'), 5000, 1)");
        $variantId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO production_specs (variant_id, material_id, pieces_per_sheet) SELECT {$variantId}, id, 10 FROM materials WHERE code = 'MDF-CRU-03'");
        $specId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO production_spec_steps (spec_id, stage, estimated_minutes, is_passive, sort_order) VALUES ({$specId}, 'cnc', {$cncMinutes}, 0, 10)");

        return $variantId;
    }

    /** Pedido pago com as variantes informadas. @param array<int, int> $lines variante => qtd @return array<string, mixed> */
    private function paidOrder(array $lines): array
    {
        $this->newBrowser();
        foreach ($lines as $variantId => $qty) {
            $this->post('/carrinho/itens', ['variant_id' => (string) $variantId, 'quantity' => (string) $qty]);
        }
        $placed = $this->post('/checkout', [
            'name' => 'Rita Alves', 'email' => 'rita@cliente.test', 'cpf' => '529.982.247-25', 'phone' => '(71) 98888-7777',
            'zip_code' => '40140-110', 'street' => 'Rua A', 'number' => '1', 'complement' => '', 'district' => 'Centro',
            'city' => 'Salvador', 'state' => 'BA', 'recipient_name' => '', 'shipping_code' => 'economico', 'quoted_zip' => '40140110',
        ]);
        $this->post((string) $placed->header('Location'), ['resultado' => 'aprovar']);

        return $this->db->pdo()->query('SELECT * FROM orders ORDER BY id DESC LIMIT 1')->fetch();
    }

    /** @return list<int> */
    private function jobIds(int $orderId): array
    {
        return array_map('intval', array_column($this->db->pdo()->query("SELECT id FROM production_jobs WHERE order_id = {$orderId} ORDER BY id")->fetchAll(), 'id'));
    }

    private function orderStatus(int $orderId): string
    {
        return (string) $this->fetchValue('SELECT status FROM orders WHERE id = :id', ['id' => $orderId]);
    }

    public function testOneJobPerItemAndOrderFollowsTheSlowest(): void
    {
        $order = $this->paidOrder([$this->product('peca-a') => 1, $this->product('peca-b') => 2]);
        [$a, $b] = $this->jobIds((int) $order['id']);
        $service = $this->container->get(ProductionService::class);

        self::assertSame(0, $service->createJobs((int) $order['id']), 'Idempotente');

        // A anda até o fim; o pedido fica preso em B (na fila)
        foreach (range(1, 4) as $step) {
            $service->advance($a, null);
        }
        self::assertSame('done', $this->fetchValue("SELECT stage FROM production_jobs WHERE id = {$a}"));
        self::assertSame('production_pending', $this->orderStatus((int) $order['id']));

        $service->advance($b, null);
        self::assertSame('in_production', $this->orderStatus((int) $order['id']));

        // Retrabalho só a partir do CQ e para trás
        $this->expectRule(fn () => $service->rework($b, 'cnc', null, 'motivo'), 'Retrabalho só');
        $service->advance($b, null); // → quality
        $this->expectRule(fn () => $service->rework($b, 'packaging', null, 'motivo'), 'Retrabalho só');
        $this->expectRule(fn () => $service->rework($b, 'cnc', null, ''), 'motivo');

        // Pedido cancelado: jobs saem da fila e não andam mais
        $this->container->get(OrderStatusService::class)->cancel((int) $order['id'], 'Cliente desistiu', 'admin', null, 'manual');
        self::assertSame('cancelled', $this->fetchValue("SELECT stage FROM production_jobs WHERE id = {$b}"));
        self::assertSame('done', $this->fetchValue("SELECT stage FROM production_jobs WHERE id = {$a}"), 'Pronto continua registrado');
        $this->expectRule(fn () => $service->advance($b, null), 'saiu da fila');
    }

    public function testPlannerForecastsByDeadlineAndFlagsRisk(): void
    {
        $slow = $this->product('peca-lenta', 300); // 5 h de CNC por unidade
        $late = $this->paidOrder([$slow => 1]);
        $this->db->pdo()->exec("UPDATE orders SET paid_at = UTC_TIMESTAMP() - INTERVAL 20 DAY WHERE id = {$late['id']}");
        $fresh = $this->paidOrder([$slow => 2]);

        $planner = new ProductionPlanner($this->container->get(ProductionJobRepository::class), 420);
        $plan = $planner->plan($this->container->get(ProductionJobRepository::class)->open(), '2026-09-25 12:00:00', '2026-09-25');

        self::assertSame(900, $plan['backlog_minutes'], '300 + 600 minutos de operador');
        self::assertSame((int) $late['id'], (int) $plan['jobs'][0]['order_id'], 'Prazo mais antigo primeiro');
        self::assertTrue($plan['jobs'][0]['late']);
        self::assertSame(1, $plan['late']);
        // 300 min cabem hoje; +600 = 900 → 3º dia útil (sexta 25 → terça 29)
        self::assertSame('2026-09-25', $plan['jobs'][0]['forecast']);
        self::assertSame('2026-09-29', $plan['jobs'][1]['forecast']);
        self::assertSame((int) $fresh['id'], (int) $plan['jobs'][1]['order_id']);
    }

    public function testQueuePagesPermissionsBackfillAndClaim(): void
    {
        $order = $this->paidOrder([$this->product('peca-c') => 1]);
        // Simula pedido pago antes da fila existir
        $this->db->pdo()->exec("DELETE FROM production_jobs WHERE order_id = {$order['id']}");

        $this->loginAs(AdminRole::Support);
        self::assertSame(403, $this->get('/admin/producao')->status());
        self::assertSame(403, $this->get('/admin/expedicao')->status());

        $this->loginAs(AdminRole::Production);
        $queue = $this->get('/admin/producao')->body();
        self::assertStringContainsString($order['number'], $queue, 'Backfill cria o job ao abrir a fila');
        [$jobId] = $this->jobIds((int) $order['id']);

        $this->post("/admin/producao/{$jobId}/assumir", ['back' => '/admin/producao']);
        self::assertStringContainsString($order['number'], $this->get('/admin/producao', ['meus' => '1'])->body());
        self::assertStringContainsString('Equipe Produção', $this->get("/admin/producao/{$jobId}")->body());

        $job = $this->get("/admin/producao/{$jobId}")->body();
        self::assertStringContainsString('Ficha de produção', $job);
        self::assertStringContainsString('aria-current="step"', $job);

        // back aceita só caminho interno
        self::assertSame("/admin/producao/{$jobId}", $this->post("/admin/producao/{$jobId}/avancar", ['back' => 'https://evil.test'])->header('Location'));
    }

    public function testShippingDeskSlipAndMaterialLedger(): void
    {
        $variant = $this->product('peca-d');
        $order = $this->paidOrder([$variant => 3]);
        [$jobId] = $this->jobIds((int) $order['id']);
        $service = $this->container->get(ProductionService::class);
        $stockBefore = (string) $this->fetchValue("SELECT stock_qty FROM materials WHERE code = 'MDF-CRU-03'");
        foreach (range(1, 4) as $step) {
            $service->advance($jobId, null);
        }
        self::assertSame('ready_to_ship', $this->orderStatus((int) $order['id']));

        // 3 peças a 10 por chapa = 0,30 (arredondado para cima em centésimos); saldo não fica negativo
        self::assertSame('-0.30', (string) $this->fetchValue("SELECT quantity FROM material_movements WHERE reference_id = {$jobId}"));
        self::assertSame('0.00', (string) $this->fetchValue("SELECT stock_qty FROM materials WHERE code = 'MDF-CRU-03'"));
        self::assertSame('0.00', $stockBefore);

        $this->loginAs(AdminRole::Production);
        $desk = $this->get('/admin/expedicao')->body();
        self::assertStringContainsString($order['number'], $desk);
        $slip = $this->get("/admin/expedicao/{$order['id']}/romaneio");
        self::assertStringContainsString('Romaneio', $slip->body());
        self::assertStringContainsString('40140-110', $slip->body());
        self::assertStringNotContainsString('admin-sidebar', $slip->body(), 'Layout de impressão, sem menu');

        // Entrada de chapas pelo painel
        $materialId = (int) $this->fetchValue("SELECT id FROM materials WHERE code = 'MDF-CRU-03'");
        $this->post("/admin/materiais/{$materialId}/movimento", ['direction' => 'in', 'quantity' => '10', 'reason' => 'Compra NF 55']);
        self::assertSame('10.00', (string) $this->fetchValue('SELECT stock_qty FROM materials WHERE id = :id', ['id' => $materialId]));
        $this->post("/admin/materiais/{$materialId}/movimento", ['direction' => 'in', 'quantity' => '5', 'reason' => '']);
        self::assertSame('10.00', (string) $this->fetchValue('SELECT stock_qty FROM materials WHERE id = :id', ['id' => $materialId]), 'Motivo obrigatório');
        self::assertStringContainsString('Compra NF 55', $this->get("/admin/materiais/{$materialId}/editar")->body());

        $this->post("/admin/expedicao/{$order['id']}/enviar", ['carrier' => 'Correios', 'tracking_code' => 'QA123456789BR']);
        self::assertSame('shipped', $this->orderStatus((int) $order['id']));
        $this->post("/admin/expedicao/{$order['id']}/entregue");
        self::assertSame('delivered', $this->orderStatus((int) $order['id']));
    }

    private function expectRule(callable $action, string $message): void
    {
        try {
            $action();
            self::fail("Deveria recusar: {$message}");
        } catch (BusinessRuleException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
