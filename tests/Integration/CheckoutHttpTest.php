<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\Bootstrap;
use GNesting\Core\Csrf;
use GNesting\Core\Database;
use GNesting\Core\Kernel;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Enums\PaymentStatus;
use GNesting\Services\Mail\LogMailer;
use GNesting\Services\Mail\Mailer;
use GNesting\Services\OrderService;
use GNesting\Services\Payment\GatewayPayment;
use GNesting\Services\Payment\HttpClient;
use GNesting\Services\Payment\MercadoPagoGateway;
use GNesting\Services\Payment\PaymentGateway;
use GNesting\Services\PaymentService;
use GNesting\Tests\Support\TestFiles;

/**
 * Etapa 7: checkout sem conta e com conta, frete, reserva de estoque, pagamento
 * (simulado e Mercado Pago via webhook), expiração e acesso ao pedido.
 */
final class CheckoutHttpTest extends IntegrationTestCase
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

    // ---- infraestrutura HTTP ---------------------------------------------------

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->send(new Request('GET', $path, $query, [], $this->cookies(), $this->server()));
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body = []): Response
    {
        $body += ['_token' => $this->container->get(Csrf::class)->token()];

        return $this->send(new Request('POST', $path, [], $body, $this->cookies(), $this->server()));
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

    /** @return array<string, string> */
    private function server(): array
    {
        return ['REMOTE_ADDR' => '192.0.2.70', 'HTTP_USER_AGENT' => 'PHPUnit'];
    }

    /**
     * Novo "navegador": sem cookie de carrinho e com sessão vazia. Um container novo garante
     * que nenhum serviço já resolvido guarde a sessão anterior; só a conexão (e a transação
     * do teste) é compartilhada.
     */
    private function newBrowser(): void
    {
        $this->cartCookie = null;
        $container = Bootstrap::createContainer(dirname(__DIR__, 2));
        $container->instance(Database::class, $this->db);
        $container->set(Mailer::class, fn () => $this->mailer);
        $this->container = $container;
    }

    // ---- dados ------------------------------------------------------------------

    private function referenceVariant(): int
    {
        return (int) $this->fetchValue("SELECT id FROM product_variants WHERE sku = 'REL-GEO-001'");
    }

    /** Produto de pronta entrega com N unidades. */
    private function stockVariant(int $quantity): int
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO products (category_id, name, slug, is_active, production_lead_days, published_at)
                    SELECT id, 'Luminária Pronta', 'luminaria-pronta', 1, 2, UTC_TIMESTAMP() FROM categories WHERE slug = 'decoracao'");
        $productId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO product_variants (product_id, sku, price_cents, is_default, package_weight_g) VALUES ({$productId}, 'LUM-PR-01', 25000, 1, 1500)");
        $variantId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO inventory (variant_id, stock_mode, quantity_on_hand) VALUES ({$variantId}, 'stock', {$quantity})");

        return $variantId;
    }

    private function nameRuleId(): int
    {
        return (int) $this->fetchValue("SELECT id FROM personalization_rules WHERE field_key = 'nome_gravado'");
    }

    /** @return array<string, string> */
    private function checkoutForm(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Ana Souza', 'email' => 'ana@cliente.test', 'cpf' => '529.982.247-25', 'phone' => '(71) 99999-8888',
            'zip_code' => '40140-110', 'street' => 'Rua das Flores', 'number' => '100', 'complement' => 'Apto 2',
            'district' => 'Ondina', 'city' => 'Salvador', 'state' => 'BA', 'recipient_name' => '',
            'shipping_code' => 'economico', 'quoted_zip' => '40140110',
        ];
    }

    /** @return array<string, mixed> */
    private function lastOrder(): array
    {
        return $this->db->pdo()->query('SELECT * FROM orders ORDER BY id DESC LIMIT 1')->fetch();
    }

    // ---- fluxo sem conta ----------------------------------------------------------

    public function testGuestCheckoutReservesStockAndSimulatedPaymentReleasesToProduction(): void
    {
        $lamp = $this->stockVariant(3);
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '2', 'pers_' . $this->nameRuleId() => 'Ana']);
        $this->post('/carrinho/itens', ['variant_id' => (string) $lamp, 'quantity' => '1']);

        // "Calcular frete" sem JavaScript: volta ao formulário com opções para o CEP
        $quoted = $this->post('/checkout', ['action' => 'quote', 'zip_code' => '40140-110']);
        self::assertSame('/checkout', $quoted->header('Location'));
        $page = $this->get('/checkout')->body();
        self::assertStringContainsString('name="shipping_code" value="economico"', $page);
        self::assertStringContainsString('Para o CEP 40140-110', $page);

        $placed = $this->post('/checkout', $this->checkoutForm(['shipping_price' => '0,01', 'subtotal' => '1']));
        self::assertMatchesRegularExpression('#^/pagamento-simulado/SIM-[a-f0-9]{16}$#', (string) $placed->header('Location'));

        $order = $this->lastOrder();
        self::assertMatchesRegularExpression('/^GN-\d{4}-\d{6}$/', $order['number']);
        self::assertSame('awaiting_payment', $order['status']);
        // 2 × (129,90 + 15,00 de nome gravado) + 250,00 = 539,80; frete BA local (3,1 kg: 1990 + 3 × 400)
        self::assertSame(53980, (int) $order['subtotal_cents'], 'Preço recalculado no servidor; campos forjados ignorados');
        self::assertSame(1990 + 3 * 400, (int) $order['shipping_cents']);
        self::assertSame((int) $order['subtotal_cents'] + (int) $order['shipping_cents'], (int) $order['total_cents']);
        self::assertSame('52998224725', $order['customer_cpf']);
        self::assertSame('5571999998888', $order['customer_phone']);
        self::assertSame('Ana Souza', $order['ship_recipient'], 'Quem recebe vazio = o comprador');
        self::assertSame(3, (int) $order['production_days']);

        $item = $this->db->pdo()->query("SELECT * FROM order_items WHERE order_id = {$order['id']} AND sku = 'REL-GEO-001'")->fetch();
        self::assertSame(12990, (int) $item['unit_price_cents']);
        self::assertSame(1500, (int) $item['personalization_cents']);
        self::assertSame(126, (int) $item['production_minutes_estimate'], 'Snapshot da ficha de produção');
        self::assertSame('Ana', $this->fetchValue("SELECT value_text FROM order_item_personalizations WHERE field_key = 'nome_gravado'"));

        self::assertSame(1, (int) $this->fetchValue('SELECT quantity_reserved FROM inventory WHERE variant_id = :v', ['v' => $lamp]));
        self::assertSame('converted', $this->fetchValue('SELECT status FROM carts ORDER BY id DESC LIMIT 1'));
        self::assertStringContainsString('data-count="0"', $this->get('/')->body(), 'Carrinho zerado após o pedido');
        self::assertNull($this->fetchValue('SELECT user_id FROM customers WHERE id = :id', ['id' => $order['customer_id']]) ?: null, 'Cliente sem conta');

        // E-mail com o link privado
        $mail = $this->mailer->sent()[0];
        self::assertSame('ana@cliente.test', $mail['to']);
        self::assertStringContainsString($order['number'], $mail['subject']);
        self::assertMatchesRegularExpression('#/pedido/' . $order['number'] . '/confirmacao\?chave=([a-f0-9]{48})#', $mail['body']);
        preg_match('#chave=([a-f0-9]{48})#', $mail['body'], $key);

        // Página simulada: aprovar
        $approved = $this->post((string) $placed->header('Location'), ['resultado' => 'aprovar']);
        self::assertStringStartsWith('/pedido/' . $order['number'] . '/confirmacao', (string) $approved->header('Location'));
        $order = $this->lastOrder();
        self::assertSame('production_pending', $order['status']);
        self::assertSame('paid', $order['payment_status']);
        self::assertNotNull($order['paid_at']);
        self::assertSame(['awaiting_payment', 'paid', 'production_pending'], array_column(
            $this->db->pdo()->query("SELECT to_status FROM order_status_history WHERE order_id = {$order['id']} ORDER BY id")->fetchAll(), 'to_status'));
        self::assertSame(2, (int) $this->fetchValue('SELECT quantity_on_hand FROM inventory WHERE variant_id = :v', ['v' => $lamp]), 'Reserva virou saída');
        self::assertSame(0, (int) $this->fetchValue('SELECT quantity_reserved FROM inventory WHERE variant_id = :v', ['v' => $lamp]));

        // Mesmo navegador vê o pedido; outro navegador só com a chave
        self::assertStringContainsString('Na fila de produção', $this->get('/pedido/' . $order['number'] . '/confirmacao')->body());
        $this->newBrowser();
        self::assertSame(404, $this->get('/pedido/' . $order['number'] . '/confirmacao')->status());
        self::assertSame(404, $this->get('/pedido/' . $order['number'] . '/confirmacao', ['chave' => str_repeat('a', 48)])->status());
        self::assertSame(200, $this->get('/pedido/' . $order['number'] . '/confirmacao', ['chave' => $key[1]])->status());
    }

    public function testInvalidDataReturnsToFormWithoutCreatingOrder(): void
    {
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);

        foreach ([
            [['cpf' => '111.111.111-11'], 'CPF válido'],
            [['phone' => '9999'], 'com DDD'],
            [['zip_code' => '01310-100', 'quoted_zip' => '01310100', 'state' => 'BA'], 'O CEP informado é de SP'],
            [['zip_code' => '01310-100', 'state' => 'SP'], 'Calcule o frete para o CEP'],
            [['shipping_code' => 'teletransporte'], 'Escolha uma opção de entrega'],
            [['email' => 'nao-e-email'], 'e-mail'],
        ] as [$override, $message]) {
            $response = $this->post('/checkout', $this->checkoutForm($override));
            self::assertSame('/checkout', $response->header('Location'), $message);
            self::assertStringContainsString($message, $this->get('/checkout')->body(), $message);
        }
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM orders'));

        $this->post('/checkout', $this->checkoutForm(['cpf' => '1']));
        self::assertStringContainsString('value="Rua das Flores"', $this->get('/checkout')->body(), 'Dados digitados voltam ao formulário');
    }

    public function testEmptyCartOrCartWithIssuesCannotCheckout(): void
    {
        self::assertSame('/carrinho', $this->get('/checkout')->header('Location'));
        self::assertSame('/carrinho', $this->post('/checkout', $this->checkoutForm())->header('Location'));

        $lamp = $this->stockVariant(1);
        $this->post('/carrinho/itens', ['variant_id' => (string) $lamp, 'quantity' => '1']);
        $this->db->pdo()->exec("UPDATE inventory SET quantity_on_hand = 0 WHERE variant_id = {$lamp}");
        self::assertSame('/carrinho', $this->get('/checkout')->header('Location'));
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM orders'));
    }

    public function testLastUnitCannotBeSoldTwice(): void
    {
        $lamp = $this->stockVariant(1);
        $this->post('/carrinho/itens', ['variant_id' => (string) $lamp, 'quantity' => '1']);
        $firstBrowserCart = $this->cartCookie;

        // Outro cliente compra a última unidade antes
        $this->newBrowser();
        $this->post('/carrinho/itens', ['variant_id' => (string) $lamp, 'quantity' => '1']);
        $this->post('/checkout', $this->checkoutForm(['email' => 'rapido@cliente.test']));
        self::assertSame(1, (int) $this->fetchValue('SELECT COUNT(*) FROM orders'));

        // O primeiro carrinho agora está acima do disponível: não fecha
        $this->cartCookie = $firstBrowserCart;
        self::assertSame('/carrinho', $this->post('/checkout', $this->checkoutForm())->header('Location'));
        self::assertSame(1, (int) $this->fetchValue('SELECT COUNT(*) FROM orders'));
        self::assertSame(1, (int) $this->fetchValue('SELECT quantity_reserved FROM inventory WHERE variant_id = :v', ['v' => $lamp]));
    }

    public function testUnpaidOrderExpiresAndReleasesStock(): void
    {
        $lamp = $this->stockVariant(2);
        $this->post('/carrinho/itens', ['variant_id' => (string) $lamp, 'quantity' => '2']);
        $this->post('/checkout', $this->checkoutForm());
        $order = $this->lastOrder();

        $service = $this->container->get(OrderService::class);
        self::assertSame(0, $service->expireUnpaid(48), 'Ainda dentro do prazo');
        $this->db->pdo()->exec("UPDATE orders SET placed_at = UTC_TIMESTAMP() - INTERVAL 49 HOUR WHERE id = {$order['id']}");
        self::assertSame(1, $service->expireUnpaid(48));

        $order = $this->lastOrder();
        self::assertSame('cancelled', $order['status']);
        self::assertStringContainsString('48 horas', (string) $order['cancel_reason']);
        self::assertSame(0, (int) $this->fetchValue('SELECT quantity_reserved FROM inventory WHERE variant_id = :v', ['v' => $lamp]));
        self::assertSame(2, (int) $this->fetchValue('SELECT quantity_on_hand FROM inventory WHERE variant_id = :v', ['v' => $lamp]));

        // Pagamento que chega depois do cancelamento não reativa sozinho: fica anotado
        $this->container->get(PaymentService::class)->apply(new GatewayPayment('late-1', $order['number'], PaymentStatus::Paid, (int) $order['total_cents'], 'pix'), 'webhook');
        $order = $this->lastOrder();
        self::assertSame('cancelled', $order['status']);
        self::assertStringContainsString('após o cancelamento', (string) $order['admin_notes']);
    }

    public function testRejectedPaymentCanBeRetried(): void
    {
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        $first = (string) $this->post('/checkout', $this->checkoutForm())->header('Location');
        $this->post($first, ['resultado' => 'recusar']);

        $order = $this->lastOrder();
        self::assertSame('awaiting_payment', $order['status']);
        self::assertSame('failed', $order['payment_status']);
        self::assertStringContainsString('não foi aprovado', $this->get('/pedido/' . $order['number'] . '/confirmacao')->body());

        // "Pagar agora" abre uma nova tentativa
        $retry = (string) $this->post('/pedido/' . $order['number'] . '/pagar')->header('Location');
        self::assertNotSame($first, $retry);
        $this->post($retry, ['resultado' => 'aprovar']);
        self::assertSame('production_pending', $this->lastOrder()['status']);
        self::assertSame(2, (int) $this->fetchValue('SELECT COUNT(*) FROM payments WHERE order_id = :id', ['id' => $order['id']]));
    }

    // ---- Mercado Pago (webhook) -------------------------------------------------

    public function testMercadoPagoWebhookConfirmsOnlyVerifiedMatchingPayments(): void
    {
        $responses = [];
        $http = new class ($responses) implements HttpClient {
            public function __construct(public array $responses)
            {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                return array_shift($this->responses) ?? ['status' => 500, 'body' => ''];
            }
        };
        $this->container->set(PaymentGateway::class, fn () => new MercadoPagoGateway($http, 'TOKEN', 'segredo'));

        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        $http->responses[] = ['status' => 201, 'body' => '{"id":"pref-9","init_point":"https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-9"}'];
        $placed = $this->post('/checkout', $this->checkoutForm());
        self::assertStringStartsWith('https://www.mercadopago.com.br/', (string) $placed->header('Location'), 'Checkout hospedado pelo Mercado Pago');
        $order = $this->lastOrder();
        $total = number_format(((int) $order['total_cents']) / 100, 2, '.', '');

        $webhook = function (string $paymentId, string $eventId, ?string $secret = 'segredo'): Response {
            $ts = (string) time();
            $sig = hash_hmac('sha256', "id:{$paymentId};request-id:req-{$eventId};ts:{$ts};", (string) $secret);

            return $this->container->get(Kernel::class)->handle(new Request('POST', '/webhooks/pagamento/mercadopago',
                ['data_id' => $paymentId, 'type' => 'payment'], [], [],
                ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_SIGNATURE' => "ts={$ts},v1={$sig}", 'HTTP_X_REQUEST_ID' => 'req-' . $eventId], [],
                json_encode(['id' => $eventId, 'type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => $paymentId]])));
        };

        // Assinatura inválida: 401, nada muda, evento registrado como inválido
        self::assertSame(401, $webhook('555', 'ev-0', 'outro-segredo')->status());
        self::assertSame('awaiting_payment', $this->lastOrder()['status']);
        self::assertSame(0, (int) $this->fetchValue("SELECT signature_valid FROM payment_events WHERE event_id = 'ev-0'"));

        // Valor divergente: pagamento registrado, pedido NÃO confirmado
        $http->responses[] = ['status' => 200, 'body' => json_encode(['id' => 555, 'status' => 'approved', 'external_reference' => $order['number'],
            'transaction_amount' => 1.00, 'payment_type_id' => 'bank_transfer', 'payment_method_id' => 'pix'])];
        self::assertSame(200, $webhook('555', 'ev-1')->status());
        self::assertSame('awaiting_payment', $this->lastOrder()['status']);
        self::assertStringContainsString('valor divergente', (string) $this->lastOrder()['admin_notes']);

        // Pagamento correto: confirmado
        $http->responses[] = ['status' => 200, 'body' => json_encode(['id' => 556, 'status' => 'approved', 'external_reference' => $order['number'],
            'transaction_amount' => (float) $total, 'payment_type_id' => 'credit_card', 'payment_method_id' => 'master', 'installments' => 3, 'card' => ['last_four_digits' => '1234']])];
        self::assertSame(200, $webhook('556', 'ev-2')->status());
        self::assertSame('production_pending', $this->lastOrder()['status']);
        $payment = $this->db->pdo()->query("SELECT * FROM payments WHERE provider_payment_id = '556'")->fetch();
        self::assertSame('paid', $payment['status']);
        self::assertSame('1234', $payment['card_last4']);
        self::assertSame('credit_card', $payment['method']);

        // Aviso repetido: 200 sem reprocessar (nenhuma chamada à API é feita)
        self::assertSame(200, $webhook('556', 'ev-2')->status());
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM payment_events WHERE event_id = 'ev-2'"));
        self::assertSame(3, (int) $this->fetchValue("SELECT COUNT(*) FROM order_status_history WHERE order_id = :id", ['id' => $order['id']]));

        // Webhook de outro provedor na URL: 404
        self::assertSame(404, $this->container->get(Kernel::class)->handle(new Request('POST', '/webhooks/pagamento/pagseguro', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']))->status());
    }

    public function testReturnUrlParametersNeverConfirmPaymentByThemselves(): void
    {
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        $this->post('/checkout', $this->checkoutForm());
        $order = $this->lastOrder();

        // Simulado não tem API para reconsultar: "status=approved" na URL não muda nada
        $page = $this->get('/pedido/' . $order['number'] . '/confirmacao', ['status' => 'aprovado', 'collection_status' => 'approved', 'payment_id' => '123']);
        self::assertSame(200, $page->status());
        self::assertSame('awaiting_payment', $this->lastOrder()['status']);
    }

    // ---- com conta ----------------------------------------------------------------

    public function testLoggedCustomerSavesAddressAndSeesOnlyOwnOrders(): void
    {
        $this->post('/cadastro', ['name' => 'Bia Lima', 'email' => 'bia@cliente.test', 'password' => 'senha-muito-segura', 'password_confirmation' => 'senha-muito-segura']);
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        $this->post('/checkout', $this->checkoutForm(['name' => 'Bia Lima', 'email' => 'ignorado@x.test', 'save_address' => '1']));

        $order = $this->lastOrder();
        self::assertSame('bia@cliente.test', $order['customer_email'], 'Com conta vale o e-mail da conta');
        self::assertSame(1, (int) $this->fetchValue('SELECT COUNT(*) FROM addresses WHERE customer_id = :c', ['c' => $order['customer_id']]));
        self::assertStringContainsString($order['number'], $this->get('/conta/pedidos')->body());

        // Segunda compra: endereço salvo aparece e é usado (campos manuais em branco)
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        $page = $this->get('/checkout')->body();
        self::assertStringContainsString('Rua das Flores, 100', $page);
        $addressId = (string) $this->fetchValue('SELECT id FROM addresses WHERE customer_id = :c', ['c' => $order['customer_id']]);
        $blank = array_fill_keys(['zip_code', 'street', 'number', 'complement', 'district', 'city', 'state'], '');
        $this->post('/checkout', $blank + ['address_id' => $addressId, 'save_address' => '1'] + $this->checkoutForm(['name' => 'Bia Lima']));
        self::assertSame('Rua das Flores', $this->lastOrder()['ship_street']);
        self::assertSame(1, (int) $this->fetchValue('SELECT COUNT(*) FROM addresses WHERE customer_id = :c', ['c' => $order['customer_id']]), 'Não duplica');

        // Outro cliente logado não vê o pedido
        $this->post('/sair');
        $this->newBrowser();
        $this->post('/cadastro', ['name' => 'Caio', 'email' => 'caio@cliente.test', 'password' => 'senha-muito-segura', 'password_confirmation' => 'senha-muito-segura']);
        self::assertSame(404, $this->get('/pedido/' . $order['number'] . '/confirmacao')->status());
        self::assertStringNotContainsString($order['number'], $this->get('/conta/pedidos')->body());
    }

    public function testCartIsAdoptedWhenCustomerLogsInOnAnotherBrowser(): void
    {
        $this->post('/cadastro', ['name' => 'Dani', 'email' => 'dani@cliente.test', 'password' => 'senha-muito-segura', 'password_confirmation' => 'senha-muito-segura']);
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '2']);
        $this->post('/sair');

        $this->newBrowser();
        $this->get('/entrar');
        $this->post('/entrar', ['email' => 'dani@cliente.test', 'password' => 'senha-muito-segura']);
        self::assertStringContainsString('data-count="2"', $this->get('/carrinho')->body(), 'Carrinho da conta retomado com novo token');
    }

    public function testShippingQuoteApi(): void
    {
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);

        $ok = $this->post('/api/frete/cotar', ['cep' => '01310-100']);
        self::assertSame(200, $ok->status());
        $data = json_decode($ok->body(), true);
        self::assertSame('SP', $data['state']);
        self::assertSame('economico', $data['options'][0]['code']);

        self::assertSame(422, $this->post('/api/frete/cotar', ['cep' => '123'])->status());
        self::assertSame(419, $this->send(new Request('POST', '/api/frete/cotar', [], ['cep' => '01310100'], $this->cookies(), $this->server()))->status(), 'Exige CSRF');
    }
}
