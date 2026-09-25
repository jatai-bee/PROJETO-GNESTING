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
use GNesting\Services\AdminUserService;
use GNesting\Services\Mail\LogMailer;
use GNesting\Services\Mail\Mailer;
use GNesting\Services\OrderStatusService;
use GNesting\Services\SeoData;
use GNesting\Tests\Support\TestFiles;

/** Etapa 10: cupons, SEO, WhatsApp/configurações e relacionados. */
final class MarketingTest extends IntegrationTestCase
{
    private ?string $cartCookie = null;
    private string $ip = '192.0.2.100';

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
        return $this->send(new Request('GET', $path, $query, [], $this->cookies(), ['REMOTE_ADDR' => $this->ip]));
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body = []): Response
    {
        return $this->send(new Request('POST', $path, [], $body + ['_token' => $this->container->get(Csrf::class)->token()], $this->cookies(), ['REMOTE_ADDR' => $this->ip]));
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

    private function loginAs(AdminRole $role): void
    {
        $this->newBrowser();
        $email = $role->value . '@mkt.test';
        if (!$this->fetchValue('SELECT 1 FROM users WHERE email = :e', ['e' => $email])) {
            $this->container->get(AdminUserService::class)->create('Equipe', $email, $role, 'senha-muito-segura');
        }
        $this->get('/admin/login');
        $this->post('/admin/login', ['email' => $email, 'password' => 'senha-muito-segura']);
    }

    private function coupon(string $code, string $type, int $value, array $extra = []): int
    {
        $data = $extra + ['min_subtotal_cents' => null, 'max_discount_cents' => null, 'starts_at' => null, 'ends_at' => null,
            'usage_limit' => null, 'usage_limit_per_customer' => null, 'is_active' => 1];
        $this->db->pdo()->prepare(
            'INSERT INTO coupons (code, type, value, min_subtotal_cents, max_discount_cents, starts_at, ends_at, usage_limit, usage_limit_per_customer, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$code, $type, $value, $data['min_subtotal_cents'], $data['max_discount_cents'], $data['starts_at'], $data['ends_at'],
            $data['usage_limit'], $data['usage_limit_per_customer'], $data['is_active']]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function referenceVariant(): int
    {
        return (int) $this->fetchValue("SELECT id FROM product_variants WHERE sku = 'REL-GEO-001'");
    }

    /** @return array<string, string> */
    private function checkoutForm(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Ana Souza', 'email' => 'ana@cliente.test', 'cpf' => '529.982.247-25', 'phone' => '(71) 99999-8888',
            'zip_code' => '01310-100', 'street' => 'Av. Paulista', 'number' => '1000', 'complement' => '', 'district' => 'Bela Vista',
            'city' => 'São Paulo', 'state' => 'SP', 'recipient_name' => '', 'shipping_code' => 'economico', 'quoted_zip' => '01310100',
        ];
    }

    /** @return array<string, mixed> */
    private function lastOrder(): array
    {
        return $this->db->pdo()->query('SELECT * FROM orders ORDER BY id DESC LIMIT 1')->fetch();
    }

    // ---- Cupons ---------------------------------------------------------------------

    public function testPercentCouponFromCartToOrderAndReleaseOnCancel(): void
    {
        $id = $this->coupon('BEMVINDO10', 'percent', 1000, ['max_discount_cents' => 5000]);
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '2']);

        $this->post('/carrinho/cupom', ['code' => ' bemvindo10 ']);
        $cart = $this->get('/carrinho')->body();
        self::assertStringContainsString('Cupom aplicado: 10% de desconto', $cart);
        self::assertStringContainsString('− R$ 25,98', $cart, '10% de 259,80');
        self::assertStringContainsString('R$ 233,82', $cart);
        self::assertStringContainsString('− R$ 25,98', $this->get('/checkout')->body());

        $this->post('/checkout', $this->checkoutForm());
        $order = $this->lastOrder();
        self::assertSame(2598, (int) $order['discount_cents']);
        self::assertSame('BEMVINDO10', $order['coupon_code']);
        self::assertSame(25980 - 2598 + (int) $order['shipping_cents'], (int) $order['total_cents']);
        self::assertSame(1, (int) $this->fetchValue('SELECT times_used FROM coupons WHERE id = :id', ['id' => $id]));
        self::assertSame(2598, (int) $this->fetchValue('SELECT discount_cents FROM coupon_redemptions WHERE order_id = :o', ['o' => $order['id']]));
        self::assertStringContainsString('Desconto (BEMVINDO10)', $this->get('/pedido/' . $order['number'] . '/confirmacao')->body());

        $this->container->get(OrderStatusService::class)->cancel((int) $order['id'], 'Teste', 'admin', null, 'none');
        self::assertSame(0, (int) $this->fetchValue('SELECT times_used FROM coupons WHERE id = :id', ['id' => $id]), 'Uso devolvido');
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM coupon_redemptions'));
    }

    public function testCouponRulesAreEnforced(): void
    {
        $this->coupon('MINIMO', 'fixed', 1000, ['min_subtotal_cents' => 50000]);
        $this->coupon('VENCIDO', 'fixed', 1000, ['ends_at' => '2020-01-01 00:00:00']);
        $this->coupon('FUTURO', 'fixed', 1000, ['starts_at' => '2099-01-01 00:00:00']);
        $this->coupon('INATIVO', 'fixed', 1000, ['is_active' => 0]);
        $this->coupon('ESGOTADO', 'fixed', 1000, ['usage_limit' => 1]);
        $this->db->pdo()->exec("UPDATE coupons SET times_used = 1 WHERE code = 'ESGOTADO'");

        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        foreach (['MINIMO' => 'a partir de R$ 500,00', 'VENCIDO' => 'não está válido', 'FUTURO' => 'não está válido',
                     'INATIVO' => 'não está válido', 'ESGOTADO' => 'totalmente utilizado', 'NAOEXISTE' => 'não encontrado'] as $code => $message) {
            $this->post('/carrinho/cupom', ['code' => $code]);
            self::assertStringContainsString($message, $this->get('/carrinho')->body(), $code);
        }
        self::assertNull($this->fetchValue('SELECT coupon_id FROM carts ORDER BY id DESC LIMIT 1') ?: null);

        // Cupom que deixa de valer depois de aplicado: aviso no carrinho e checkout bloqueado
        $this->coupon('ATE-HOJE', 'fixed', 1000);
        $this->post('/carrinho/cupom', ['code' => 'ATE-HOJE']);
        $this->db->pdo()->exec("UPDATE coupons SET is_active = 0 WHERE code = 'ATE-HOJE'");
        self::assertStringContainsString('não está válido', $this->get('/carrinho')->body());
        self::assertSame('/carrinho', $this->post('/checkout', $this->checkoutForm())->header('Location'));
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM orders'));
    }

    public function testPerCustomerLimitIsCheckedAtCheckout(): void
    {
        $this->coupon('UMAVEZ', 'fixed', 1000, ['usage_limit_per_customer' => 1]);
        foreach ([1, 2] as $attempt) {
            $this->newBrowser();
            $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
            $this->post('/carrinho/cupom', ['code' => 'UMAVEZ']);
            $response = $this->post('/checkout', $this->checkoutForm());
        }
        self::assertSame('/carrinho', $response->header('Location'), 'Segunda compra com o mesmo e-mail');
        self::assertStringContainsString('o máximo de vezes', $this->get('/carrinho')->body());
        self::assertSame(1, (int) $this->fetchValue('SELECT COUNT(*) FROM orders'));
    }

    public function testFreeShippingCoversOnlyTheCheapestOption(): void
    {
        $this->coupon('FRETEGRATIS', 'free_shipping', 0);
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        $this->post('/carrinho/cupom', ['code' => 'FRETEGRATIS']);
        self::assertStringContainsString('grátis na opção econômica', $this->get('/carrinho')->body());

        // SP: econômico 29,90 / expresso 49,90 (1 kg)
        $this->post('/checkout', $this->checkoutForm(['shipping_code' => 'expresso']));
        $order = $this->lastOrder();
        self::assertSame(4990, (int) $order['shipping_cents']);
        self::assertSame(2990, (int) $order['discount_cents'], 'Abate o valor do econômico');
        self::assertSame(12990 + 2000, (int) $order['total_cents'], 'Paga só a diferença');
    }

    public function testCouponAttemptsAreRateLimited(): void
    {
        $this->ip = '192.0.2.101';
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        for ($i = 0; $i < 10; $i++) {
            $this->post('/carrinho/cupom', ['code' => 'CHUTE' . $i]);
        }
        $this->coupon('VALIDO', 'fixed', 500);
        $this->post('/carrinho/cupom', ['code' => 'VALIDO']);
        self::assertStringContainsString('Muitas tentativas de cupom', $this->get('/carrinho')->body());
    }

    public function testCouponAdmin(): void
    {
        $this->loginAs(AdminRole::Support);
        self::assertSame(403, $this->get('/admin/cupons')->status());

        $this->loginAs(AdminRole::Manager);
        $form = ['code' => 'natal-2026', 'description' => 'Natal', 'type' => 'percent', 'percent' => '12,5', 'fixed' => '',
            'min_subtotal' => '100,00', 'max_discount' => '', 'starts_at' => '2026-12-01T00:00', 'ends_at' => '2026-12-26T00:00',
            'usage_limit' => '100', 'usage_limit_per_customer' => '1', 'is_active' => '1'];
        self::assertSame('/admin/cupons', $this->post('/admin/cupons/novo', $form)->header('Location'));
        $coupon = $this->db->pdo()->query("SELECT * FROM coupons WHERE code = 'NATAL-2026'")->fetch();
        self::assertSame(1250, (int) $coupon['value']);
        self::assertSame('2026-12-01 03:00:00', $coupon['starts_at'], 'Horário da loja convertido para UTC');

        self::assertSame('/admin/cupons/novo', $this->post('/admin/cupons/novo', $form)->header('Location'));
        self::assertStringContainsString('Já existe', $this->get('/admin/cupons/novo')->body());

        $this->db->pdo()->exec("UPDATE coupons SET times_used = 3 WHERE code = 'NATAL-2026'");
        $this->post('/admin/cupons/' . $coupon['id'] . '/excluir');
        self::assertStringContainsString('Desative-o', $this->get('/admin/cupons')->body());
        self::assertStringContainsString('12,5% de desconto', $this->get('/admin/cupons')->body());
    }

    // ---- WhatsApp e configurações -------------------------------------------------

    public function testSettingsDriveWhatsappLinksAndAnnouncement(): void
    {
        self::assertStringNotContainsString('wa.me', $this->get('/')->body(), 'Sem número, sem botão');

        $this->loginAs(AdminRole::Manager);
        self::assertSame(403, $this->get('/admin/configuracoes')->status(), 'Só o proprietário');

        $this->loginAs(AdminRole::Owner);
        $this->post('/admin/configuracoes', ['whatsapp_number' => '123', 'whatsapp_default_message' => 'Oi', 'store_contact_email' => 'x@y.test', 'store_announcement' => '']);
        self::assertStringContainsString('com DDD', $this->get('/admin/configuracoes')->body());
        $this->post('/admin/configuracoes', ['whatsapp_number' => '(71) 99999-8888', 'whatsapp_default_message' => 'Olá G-Nesting',
            'whatsapp_floating_button' => '1', 'store_contact_email' => 'loja@gnesting.test', 'store_announcement' => 'Frete grátis acima de R$ 300']);

        $this->newBrowser();
        $home = $this->get('/')->body();
        self::assertStringContainsString('class="whatsapp-float" href="https://wa.me/5571999998888?text=Ol%C3%A1%20G-Nesting"', $home);
        self::assertStringContainsString('Frete grátis acima de R$ 300', $home);
        self::assertStringContainsString('loja@gnesting.test', $home);
        $product = $this->get('/produto/relogio-geometrico-g-nesting')->body();
        self::assertStringContainsString('Rel%C3%B3gio%20Geom%C3%A9trico%20G-Nesting', $product, 'Mensagem com o nome do produto');
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'settings'"));
    }

    // ---- SEO ----------------------------------------------------------------------

    public function testSitemapRobotsAndStructuredData(): void
    {
        $this->db->pdo()->exec("INSERT INTO products (category_id, name, slug, is_active) SELECT id, 'Rascunho', 'rascunho', 0 FROM categories WHERE slug = 'decoracao'");

        $sitemap = $this->get('/sitemap.xml');
        self::assertSame('application/xml; charset=UTF-8', $sitemap->header('Content-Type'));
        self::assertStringContainsString('/produto/relogio-geometrico-g-nesting</loc>', $sitemap->body());
        self::assertStringContainsString('/categoria/relogios</loc>', $sitemap->body());
        self::assertStringNotContainsString('rascunho', $sitemap->body());
        self::assertNotFalse(simplexml_load_string($sitemap->body()), 'XML válido');

        $robots = $this->get('/robots.txt')->body();
        self::assertStringContainsString('Disallow: /', $robots, 'Fora de produção nada é indexado');

        $page = $this->get('/produto/relogio-geometrico-g-nesting')->body();
        preg_match_all('#<script type="application/ld\+json">(.+?)</script>#s', $page, $blocks);
        $product = json_decode($blocks[1][0], true);
        self::assertSame('Product', $product['@type']);
        self::assertSame('129.90', $product['offers']['price']);
        self::assertSame('https://schema.org/InStock', $product['offers']['availability']);
        self::assertSame('BreadcrumbList', json_decode($blocks[1][1], true)['@type']);
        self::assertStringContainsString('<meta property="og:type" content="product">', $page);
        self::assertStringContainsString('og-default.png', $this->get('/')->body());

        // Nome malicioso não fecha a tag do JSON-LD
        self::assertStringNotContainsString('</script>', SeoData::encode(['name' => 'X</script><script>alert(1)</script>']));
    }

    // ---- Relacionados --------------------------------------------------------------

    public function testBoughtTogetherComesFirst(): void
    {
        $pdo = $this->db->pdo();
        $ids = [];
        foreach (['Suporte Par', 'Bandeja Solo'] as $name) {
            $pdo->exec("INSERT INTO products (category_id, name, slug, is_active, sales_count, published_at)
                        SELECT id, '{$name}', '" . slugify($name) . "', 1, " . ($name === 'Bandeja Solo' ? 99 : 0) . ", UTC_TIMESTAMP() FROM categories WHERE slug = 'escritorio'");
            $productId = (int) $pdo->lastInsertId();
            $pdo->exec("INSERT INTO product_variants (product_id, sku, price_cents, is_default) VALUES ({$productId}, '" . strtoupper(slugify($name)) . "', 3000, 1)");
            $ids[$name] = [$productId, (int) $pdo->lastInsertId()];
        }

        // Um pedido pago com relógio + suporte
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        $this->post('/carrinho/itens', ['variant_id' => (string) $ids['Suporte Par'][1], 'quantity' => '1']);
        $placed = $this->post('/checkout', $this->checkoutForm());
        $this->post((string) $placed->header('Location'), ['resultado' => 'aprovar']);

        $page = $this->get('/produto/relogio-geometrico-g-nesting')->body();
        $related = substr($page, (int) strpos($page, 'Você também pode gostar'));
        self::assertLessThan(strpos($related, 'Bandeja Solo'), strpos($related, 'Suporte Par'), 'Comprado junto antes do mais vendido');

        $this->newBrowser();
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        $cart = $this->get('/carrinho')->body();
        self::assertStringContainsString('Combina com o seu pedido', $cart);
        self::assertStringContainsString('Suporte Par', $cart);
    }
}
