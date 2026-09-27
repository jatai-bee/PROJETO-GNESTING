<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\Csrf;
use GNesting\Core\Kernel;
use GNesting\Core\Request;
use GNesting\Core\Response;

/**
 * Vitrine pelo pipeline HTTP completo: home, listagens, filtros, busca,
 * página de produto, páginas institucionais e carrinho (cookie + CSRF).
 */
final class StorefrontHttpTest extends IntegrationTestCase
{
    private Kernel $kernel;
    private ?string $cartCookie = null;
    private string $ip = '192.0.2.40';

    protected function setUp(): void
    {
        parent::setUp();
        $this->kernel = $this->container->get(Kernel::class);
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->send(new Request('GET', $path, $query, [], $this->cookies(), $this->server()));
    }

    /** @param array<string, string> $body */
    private function post(string $path, array $body = [], bool $withToken = true): Response
    {
        if ($withToken) {
            $body += ['_token' => $this->container->get(Csrf::class)->token()];
        }

        return $this->send(new Request('POST', $path, [], $body, $this->cookies(), $this->server()));
    }

    private function send(Request $request): Response
    {
        $response = $this->kernel->handle($request);
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
        return ['REMOTE_ADDR' => $this->ip, 'HTTP_USER_AGENT' => 'PHPUnit'];
    }

    private function categoryId(string $slug): int
    {
        return (int) $this->fetchValue('SELECT id FROM categories WHERE slug = :slug', ['slug' => $slug]);
    }

    /**
     * Produto visível (ativo) com variante padrão e estoque.
     *
     * @param array<string, mixed> $overrides
     * @return array{id: int, variant_id: int}
     */
    private function product(string $name, int $priceCents, array $overrides = []): array
    {
        $o = $overrides + [
            'category' => 'decoracao', 'active' => 1, 'featured' => 0, 'new' => 0, 'sales' => 0,
            'stock_mode' => 'made_to_order', 'qty' => 0, 'compare' => null, 'short' => 'Objeto decorativo',
        ];
        $pdo = $this->db->pdo();
        $slug = slugify($name);
        $pdo->prepare(
            'INSERT INTO products (category_id, name, slug, short_description, highlights, production_lead_days,
                                   is_active, is_featured, is_new, sales_count, published_at)
             VALUES (?, ?, ?, ?, ?, 4, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$this->categoryId($o['category']), $name, $slug, $o['short'], "Recorte CNC\nAcabamento natural",
            $o['active'], $o['featured'], $o['new'], $o['sales']]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, price_cents, compare_at_price_cents, material_label, width_mm, height_mm, is_default)
             VALUES (?, ?, ?, ?, ?, 300, 200, 1)'
        )->execute([$id, strtoupper(substr($slug, 0, 30)) . '-' . $id, $priceCents, $o['compare'], 'MDF 6 mm']);
        $variantId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO inventory (variant_id, stock_mode, quantity_on_hand) VALUES (?, ?, ?)')
            ->execute([$variantId, $o['stock_mode'], $o['qty']]);

        return ['id' => $id, 'variant_id' => $variantId];
    }

    private function cartItemId(int $variantId): int
    {
        return (int) $this->fetchValue('SELECT id FROM cart_items WHERE variant_id = :v ORDER BY id DESC LIMIT 1', ['v' => $variantId]);
    }

    // ---- Vitrine -------------------------------------------------------------

    public function testHomeShowsFeaturedProductsCategoriesAndEmptyCart(): void
    {
        $body = $this->get('/')->body();

        self::assertStringContainsString('Relógio Geométrico G-Nesting', $body, 'Produto de referência do seed está em destaque');
        self::assertStringContainsString('href="/categoria/relogios"', $body);
        self::assertStringContainsString('data-count="0"', $body, 'Carrinho vazio no cabeçalho');
        self::assertStringContainsString('<link rel="canonical"', $body);
    }

    public function testListingHidesInactiveProductsAndInactiveCategories(): void
    {
        $this->product('Painel Visível', 15000);
        $this->product('Painel Rascunho', 15000, ['active' => 0]);
        $this->product('Caixa Escondida', 9000, ['category' => 'caixas-e-presentes']);
        $this->db->pdo()->exec("UPDATE categories SET is_active = 0 WHERE slug = 'caixas-e-presentes'");

        $body = $this->get('/produtos')->body();
        self::assertStringContainsString('Painel Visível', $body);
        self::assertStringNotContainsString('Painel Rascunho', $body);
        self::assertStringNotContainsString('Caixa Escondida', $body);

        self::assertSame(404, $this->get('/produto/painel-rascunho')->status());
        self::assertSame(404, $this->get('/produto/caixa-escondida')->status());
        self::assertSame(404, $this->get('/categoria/caixas-e-presentes')->status());
        self::assertSame(404, $this->get('/categoria/nao-existe')->status());
    }

    public function testCategoryIncludesSubcategoryProducts(): void
    {
        $parent = $this->categoryId('decoracao');
        $this->db->pdo()->prepare("INSERT INTO categories (parent_id, name, slug) VALUES (?, 'Vasos', 'vasos')")->execute([$parent]);
        $this->product('Vaso Facetado', 8900, ['category' => 'vasos']);
        $this->product('Relógio Solto', 11000, ['category' => 'relogios']);

        $body = $this->get('/categoria/decoracao')->body();
        self::assertStringContainsString('Vaso Facetado', $body, 'Produto da subcategoria aparece na categoria-mãe');
        self::assertStringNotContainsString('Relógio Solto', $body);
        self::assertStringContainsString('href="/categoria/vasos"', $body, 'Subcategoria listada como atalho');

        $sub = $this->get('/categoria/vasos')->body();
        self::assertStringContainsString('Vaso Facetado', $sub);
        self::assertStringContainsString('href="/categoria/decoracao"', $sub, 'Breadcrumb leva à categoria-mãe');
    }

    public function testPriceFilterSortingAndPagination(): void
    {
        $this->product('Peça Barata', 5000);
        $this->product('Peça Média', 20000);
        $this->product('Peça Cara', 90000);

        $filtered = $this->get('/categoria/decoracao', ['min' => '100', 'max' => '500,00'])->body();
        self::assertStringContainsString('Peça Média', $filtered);
        self::assertStringNotContainsString('Peça Barata', $filtered);
        self::assertStringNotContainsString('Peça Cara', $filtered);
        self::assertStringContainsString('name="robots" content="noindex, follow"', $filtered, 'Listagem filtrada não é indexada');
        self::assertStringContainsString('<link rel="canonical" href="' . absolute_url('/categoria/decoracao') . '">', $filtered, 'Canônica sem filtros');

        $sorted = $this->get('/categoria/decoracao', ['ordem' => 'menor-preco'])->body();
        self::assertLessThan(strpos($sorted, 'Peça Média'), strpos($sorted, 'Peça Barata'));
        self::assertLessThan(strpos($sorted, 'Peça Cara'), strpos($sorted, 'Peça Média'));

        // Ordenação inválida cai no padrão, sem erro
        self::assertSame(200, $this->get('/produtos', ['ordem' => "'; DROP TABLE products; --"])->status());

        for ($i = 1; $i <= 12; $i++) {
            $this->product("Série {$i}", 1000 + $i);
        }
        $page1 = $this->get('/categoria/decoracao', ['ordem' => 'menor-preco'])->body();
        self::assertStringContainsString('página 1 de 2', $page1);
        self::assertStringContainsString('pagina=2', $page1);
        $page2 = $this->get('/categoria/decoracao', ['ordem' => 'menor-preco', 'pagina' => '2'])->body();
        self::assertStringContainsString('Peça Cara', $page2, 'O mais caro fica na última página');
    }

    public function testSearchByNameAndSkuWithoutListingEverythingForEmptyQuery(): void
    {
        $this->product('Organizador Colmeia', 7900, ['category' => 'organizadores']);

        $byName = $this->get('/busca', ['q' => 'colmeia'])->body();
        self::assertStringContainsString('Organizador Colmeia', $byName);
        self::assertStringContainsString('name="robots" content="noindex', $byName);

        self::assertStringContainsString('Relógio Geométrico', $this->get('/busca', ['q' => 'REL-GEO'])->body(), 'Busca por SKU');
        self::assertStringContainsString('Nenhum produto encontrado', $this->get('/busca', ['q' => 'inexistente xyz'])->body());

        $empty = $this->get('/busca', ['q' => 'a'])->body();
        self::assertStringNotContainsString('Organizador Colmeia', $empty, 'Termo curto demais não lista o catálogo');
        self::assertStringContainsString('Digite pelo menos 2 letras', $empty);

        // Caracteres curinga do LIKE são tratados como texto
        self::assertStringContainsString('Nenhum produto encontrado', $this->get('/busca', ['q' => '%%'])->body());
    }

    public function testSearchIsRateLimitedPerIp(): void
    {
        $this->ip = '192.0.2.99';
        [$max] = config('security.rate_limits.search');
        for ($i = 0; $i < $max; $i++) {
            $this->get('/busca', ['q' => 'relogio']);
        }

        $blocked = $this->get('/busca', ['q' => 'relogio']);
        self::assertSame(429, $blocked->status());
        self::assertNotNull($blocked->header('Retry-After'));
    }

    public function testProductPageShowsDetailsAndRelatedProducts(): void
    {
        $this->product('Relógio Minimal', 9900, ['category' => 'relogios', 'compare' => 12900]);

        $response = $this->get('/produto/relogio-geometrico-g-nesting');
        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('R$ 129,90', $body);
        self::assertStringContainsString('Máquina de ponteiro silenciosa', $body, 'Características em lista');
        self::assertStringContainsString('35 × 35 × 0,6 cm', $body);
        self::assertStringContainsString('REL-GEO-001', $body);
        self::assertStringContainsString('3 dias úteis', $body, 'Prazo de produção sob encomenda');
        self::assertStringContainsString('action="/carrinho/itens"', $body);
        self::assertStringContainsString('Relógio Minimal', $body, 'Relacionado da mesma categoria');
        self::assertStringContainsString('/produto/relogio-geometrico-g-nesting"', $body);

        $sale = $this->get('/produto/relogio-minimal')->body();
        self::assertStringContainsString('R$ 129,00', $sale, 'Preço "de"');
        self::assertStringContainsString('23% off', $sale);
    }

    public function testSoldOutProductHasNoBuyButton(): void
    {
        $this->product('Suporte Esgotado', 4500, ['stock_mode' => 'stock', 'qty' => 0]);

        $body = $this->get('/produto/suporte-esgotado')->body();
        self::assertStringContainsString('Produto esgotado', $body);
        self::assertStringNotContainsString('Adicionar ao carrinho', $body);
    }

    public function testInstitutionalPages(): void
    {
        foreach (['/sobre', '/como-fazemos', '/trocas-e-devolucoes', '/privacidade', '/termos'] as $path) {
            self::assertSame(200, $this->get($path)->status(), $path);
        }
        self::assertStringContainsString('art. 49', $this->get('/trocas-e-devolucoes')->body());
    }

    // ---- Carrinho ------------------------------------------------------------

    public function testAddUpdateAndRemoveCartItems(): void
    {
        $variantId = (int) $this->fetchValue("SELECT id FROM product_variants WHERE sku = 'REL-GEO-001'");

        $added = $this->post('/carrinho/itens', ['variant_id' => (string) $variantId, 'quantity' => '2']);
        self::assertSame(303, $added->status());
        self::assertSame('/carrinho', $added->header('Location'));
        $cookie = $added->cookies()['gn_cart'] ?? null;
        self::assertNotNull($cookie, 'Carrinho novo grava o cookie');
        self::assertTrue($cookie['options']['httponly']);
        self::assertSame('Lax', $cookie['options']['samesite']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cookie['value']);
        self::assertSame(1, (int) $this->fetchValue('SELECT COUNT(*) FROM carts WHERE token_hash = :h', ['h' => hash('sha256', $cookie['value'])]), 'Banco guarda só o hash');

        $cart = $this->get('/carrinho')->body();
        self::assertStringContainsString('Relógio Geométrico G-Nesting', $cart);
        self::assertStringContainsString('R$ 259,80', $cart, 'Subtotal recalculado: 2 × 129,90');
        self::assertStringContainsString('data-count="2"', $cart);

        // Adicionar de novo soma na mesma linha, sem novo cookie
        $again = $this->post('/carrinho/itens', ['variant_id' => (string) $variantId, 'quantity' => '1']);
        self::assertArrayNotHasKey('gn_cart', $again->cookies());
        self::assertSame(3, (int) $this->fetchValue('SELECT quantity FROM cart_items WHERE variant_id = :v', ['v' => $variantId]));

        $itemId = $this->cartItemId($variantId);
        $this->post("/carrinho/itens/{$itemId}", ['quantity' => '5']);
        self::assertStringContainsString('R$ 649,50', $this->get('/carrinho')->body());

        // Preço muda no admin: carrinho reflete o preço atual
        $this->db->pdo()->exec("UPDATE product_variants SET price_cents = 10000 WHERE sku = 'REL-GEO-001'");
        self::assertStringContainsString('R$ 500,00', $this->get('/carrinho')->body());

        $this->post("/carrinho/itens/{$itemId}/remover");
        self::assertStringContainsString('Seu carrinho está vazio', $this->get('/carrinho')->body());
    }

    public function testQuantityZeroRemovesAndInvalidQuantityIsRejected(): void
    {
        $p = $this->product('Porta-Canetas', 3500);
        $this->post('/carrinho/itens', ['variant_id' => (string) $p['variant_id'], 'quantity' => '1']);
        $itemId = $this->cartItemId($p['variant_id']);

        foreach (['-3', '100', 'abc', '1.5'] as $invalid) {
            $this->post("/carrinho/itens/{$itemId}", ['quantity' => $invalid]);
            self::assertSame(1, (int) $this->fetchValue('SELECT quantity FROM cart_items WHERE id = :id', ['id' => $itemId]), $invalid);
        }
        self::assertStringContainsString('entre 1 e 99', $this->get('/carrinho')->body());

        $this->post("/carrinho/itens/{$itemId}", ['quantity' => '0']);
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM cart_items WHERE id = :id', ['id' => $itemId]));
    }

    public function testStockLimitsForReadyStockProducts(): void
    {
        $p = $this->product('Luminária Pronta', 25000, ['stock_mode' => 'stock', 'qty' => 3]);

        $this->post('/carrinho/itens', ['variant_id' => (string) $p['variant_id'], 'quantity' => '4', 'back' => '/produto/luminaria-pronta']);
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM cart_items WHERE variant_id = :v', ['v' => $p['variant_id']]));

        $ok = $this->post('/carrinho/itens', ['variant_id' => (string) $p['variant_id'], 'quantity' => '3']);
        self::assertSame('/carrinho', $ok->header('Location'));

        $over = $this->post('/carrinho/itens', ['variant_id' => (string) $p['variant_id'], 'quantity' => '1', 'back' => '/produto/luminaria-pronta']);
        self::assertSame('/produto/luminaria-pronta', $over->header('Location'), 'Erro volta para a página do produto');
        self::assertStringContainsString('apenas 3 unidade', $this->get('/produto/luminaria-pronta')->body());

        // Estoque caiu depois: item fica marcado e fora do total
        $this->db->pdo()->prepare('UPDATE inventory SET quantity_on_hand = 1 WHERE variant_id = ?')->execute([$p['variant_id']]);
        $cart = $this->get('/carrinho')->body();
        self::assertStringContainsString('Quantidade acima do disponível', $cart);
        self::assertStringContainsString('R$ 0,00', $cart);
    }

    public function testProductRemovedFromStoreStaysInCartAsUnavailable(): void
    {
        $p = $this->product('Bandeja Temporária', 6000);
        $this->post('/carrinho/itens', ['variant_id' => (string) $p['variant_id'], 'quantity' => '1']);
        $this->db->pdo()->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$p['id']]);

        $cart = $this->get('/carrinho')->body();
        self::assertStringContainsString('não está mais disponível', $cart);
        self::assertStringContainsString('R$ 0,00', $cart, 'Item indisponível não entra no subtotal');

        self::assertStringContainsString('não está disponível', $this->followError(
            $this->post('/carrinho/itens', ['variant_id' => (string) $p['variant_id'], 'quantity' => '1'])
        ));
    }

    // ---- Variações e personalização (etapa 5) --------------------------------

    /** Regra de texto obrigatória "Nome" (até 12, letras) + R$ 20,00. */
    private function requiredNameRule(int $productId): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO personalization_rules (product_id, field_key, label, type, is_required, min_length, max_length, charset, price_delta_cents)
             VALUES (?, 'nome', 'Nome', 'text', 1, 2, 12, 'letters', 2000)"
        )->execute([$productId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function testRequiredPersonalizationErrorsReturnToProductWithTypedValues(): void
    {
        $p = $this->product('Placa com Nome', 8000);
        $rule = $this->requiredNameRule($p['id']);

        $page = $this->get('/produto/placa-com-nome')->body();
        self::assertStringContainsString('name="pers_' . $rule . '"', $page);
        self::assertStringContainsString('+ R$ 20,00', $page);

        $missing = $this->post('/carrinho/itens', ['variant_id' => (string) $p['variant_id'], 'quantity' => '2', 'back' => '/produto/placa-com-nome']);
        self::assertSame('/produto/placa-com-nome', $missing->header('Location'));
        self::assertStringContainsString('Preencha', $this->get('/produto/placa-com-nome')->body());

        $this->post('/carrinho/itens', [
            'variant_id' => (string) $p['variant_id'], 'quantity' => '2', 'back' => '/produto/placa-com-nome', "pers_{$rule}" => 'Ana 123',
        ]);
        $back = $this->get('/produto/placa-com-nome')->body();
        self::assertStringContainsString('aceita: somente letras', $back);
        self::assertStringContainsString('value="Ana 123"', $back, 'O que foi digitado volta ao formulário');
        self::assertStringContainsString('value="2"', $back, 'Quantidade preservada');
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM carts'), 'Erro de personalização não cria carrinho');
    }

    public function testPersonalizedLinesArePricedServerSideAndKeptSeparate(): void
    {
        $p = $this->product('Placa com Nome', 8000);
        $rule = $this->requiredNameRule($p['id']);
        $add = fn (string $name, string $qty = '1') => $this->post('/carrinho/itens', [
            'variant_id' => (string) $p['variant_id'], 'quantity' => $qty, "pers_{$rule}" => $name,
            'price' => '1,00', 'price_delta_cents' => '0', // campos forjados: ignorados
        ]);

        self::assertSame('/carrinho', $add('Ana')->header('Location'));
        $add('ana ', '2'); // espaço some na normalização, mas maiúscula/minúscula conta: outra linha
        $add('Ana');
        $add('Bia');

        self::assertSame(3, (int) $this->fetchValue('SELECT COUNT(*) FROM cart_items WHERE variant_id = :v', ['v' => $p['variant_id']]));
        self::assertSame(2, (int) $this->fetchValue(
            "SELECT ci.quantity FROM cart_items ci JOIN cart_item_personalizations cip ON cip.cart_item_id = ci.id WHERE cip.value_text = 'Ana'"
        ), 'Mesma personalização soma na mesma linha');

        $cart = $this->get('/carrinho')->body();
        self::assertStringContainsString('Nome: <strong>Bia', $cart);
        self::assertStringContainsString('R$ 100,00 cada', $cart, '80,00 + 20,00 de personalização');
        self::assertStringContainsString('R$ 500,00', $cart, 'Subtotal: 5 unidades × 100,00');

        // Acréscimo muda no painel: carrinho reflete; regra desativada: item marcado e fora do total
        $this->db->pdo()->prepare('UPDATE personalization_rules SET price_delta_cents = 3000 WHERE id = ?')->execute([$rule]);
        self::assertStringContainsString('R$ 550,00', $this->get('/carrinho')->body());
        $this->db->pdo()->prepare('UPDATE personalization_rules SET is_active = 0 WHERE id = ?')->execute([$rule]);
        $changed = $this->get('/carrinho')->body();
        self::assertStringContainsString('A personalização deste item mudou', $changed);
        self::assertStringContainsString('R$ 0,00', $changed);
    }

    public function testPersonalizationOfAnotherProductIsIgnored(): void
    {
        $plain = $this->product('Porta-Copos', 3000);
        $other = $this->product('Placa com Nome', 8000);
        $rule = $this->requiredNameRule($other['id']);

        $this->post('/carrinho/itens', ['variant_id' => (string) $plain['variant_id'], 'quantity' => '1', "pers_{$rule}" => 'Ana']);
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM cart_item_personalizations'));
        self::assertSame('', $this->fetchValue('SELECT personalization_hash FROM cart_items WHERE variant_id = :v', ['v' => $plain['variant_id']]));
    }

    public function testVariantChoiceOnProductPageAndInCart(): void
    {
        $p = $this->product('Relógio Duo', 10000, ['category' => 'relogios']);
        $variants = $this->container->get(\GNesting\Services\VariantService::class);
        $variants->addOption($p['id'], 'Acabamento', 'Natural, Preto');
        $variants->generateCombinations($p['id']);
        $black = (int) $this->fetchValue("SELECT id FROM product_variants WHERE product_id = :p AND name = 'Preto'", ['p' => $p['id']]);
        $this->db->pdo()->prepare('UPDATE product_variants SET price_cents = 13000 WHERE id = ?')->execute([$black]);

        $listing = $this->get('/categoria/relogios')->body();
        self::assertStringContainsString('a partir de', $listing);

        $page = $this->get('/produto/relogio-duo')->body();
        self::assertStringContainsString('<label for="variant_id">Acabamento</label>', $page);
        self::assertStringContainsString('Preto — R$ 130,00', $page);
        self::assertStringContainsString('R$ 100,00', $page, 'Padrão exibida');
        self::assertStringContainsString('R$ 130,00</span>', $this->get('/produto/relogio-duo', ['variante' => (string) $black])->body(), 'Link direto para a variação');

        $this->post('/carrinho/itens', ['variant_id' => (string) $black, 'quantity' => '1']);
        $cart = $this->get('/carrinho')->body();
        self::assertStringContainsString('Preto', $cart);
        self::assertStringContainsString('R$ 130,00', $cart);

        // Variação desativada some da página e não pode ser comprada
        $this->db->pdo()->prepare('UPDATE product_variants SET is_active = 0 WHERE id = ?')->execute([$black]);
        self::assertStringNotContainsString('Preto — ', $this->get('/produto/relogio-duo')->body());
        self::assertStringContainsString('não está mais disponível', $this->get('/carrinho')->body());
    }

    public function testStockIsSharedAcrossPersonalizedLinesOfTheSameVariant(): void
    {
        $p = $this->product('Chaveiro Pronto', 2500, ['stock_mode' => 'stock', 'qty' => 3]);
        $this->db->pdo()->prepare(
            "INSERT INTO personalization_rules (product_id, field_key, label, type, is_required, min_length, max_length, charset)
             VALUES (?, 'inicial', 'Inicial', 'initial', 0, 1, 1, 'letters')"
        )->execute([$p['id']]);
        $rule = (int) $this->db->pdo()->lastInsertId();

        $this->post('/carrinho/itens', ['variant_id' => (string) $p['variant_id'], 'quantity' => '2', "pers_{$rule}" => 'a']);
        $this->post('/carrinho/itens', ['variant_id' => (string) $p['variant_id'], 'quantity' => '2', "pers_{$rule}" => 'b', 'back' => '/produto/chaveiro-pronto']);
        self::assertStringContainsString('apenas 3 unidade', $this->get('/produto/chaveiro-pronto')->body());

        $this->post('/carrinho/itens', ['variant_id' => (string) $p['variant_id'], 'quantity' => '1', "pers_{$rule}" => 'b']);
        self::assertSame(3, (int) $this->fetchValue('SELECT SUM(quantity) FROM cart_items'));
        self::assertStringContainsString('Inicial: <strong>A<', $this->get('/carrinho')->body(), 'Inicial em maiúscula');
    }

    public function testCartChangesRequireCsrfToken(): void
    {
        $variantId = (int) $this->fetchValue("SELECT id FROM product_variants WHERE sku = 'REL-GEO-001'");

        self::assertSame(419, $this->post('/carrinho/itens', ['variant_id' => (string) $variantId, 'quantity' => '1'], false)->status());
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM cart_items'));
    }

    public function testCannotChangeAnotherVisitorsCartItem(): void
    {
        $variantId = (int) $this->fetchValue("SELECT id FROM product_variants WHERE sku = 'REL-GEO-001'");
        $this->post('/carrinho/itens', ['variant_id' => (string) $variantId, 'quantity' => '1']);
        $itemId = $this->cartItemId($variantId);

        // Outro visitante (sem cookie, depois com cookie forjado)
        foreach ([null, str_repeat('a', 64), '../../etc/passwd'] as $cookie) {
            $this->cartCookie = $cookie;
            $this->post("/carrinho/itens/{$itemId}", ['quantity' => '7']);
            $this->post("/carrinho/itens/{$itemId}/remover");
        }

        self::assertSame(1, (int) $this->fetchValue('SELECT quantity FROM cart_items WHERE id = :id', ['id' => $itemId]));
    }

    public function testLoggedCustomerIsLinkedToCart(): void
    {
        $this->post('/cadastro', [
            'name' => 'Ana Cliente', 'email' => 'ana@cliente.test',
            'password' => 'senha-muito-segura', 'password_confirmation' => 'senha-muito-segura',
        ]);
        $variantId = (int) $this->fetchValue("SELECT id FROM product_variants WHERE sku = 'REL-GEO-001'");
        $this->post('/carrinho/itens', ['variant_id' => (string) $variantId, 'quantity' => '1']);

        $customerId = (int) $this->fetchValue("SELECT c.id FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email = 'ana@cliente.test'");
        self::assertSame($customerId, (int) $this->fetchValue('SELECT customer_id FROM carts ORDER BY id DESC LIMIT 1'));
    }

    /** Segue o redirect de erro e devolve o HTML com a mensagem flash. */
    private function followError(Response $response): string
    {
        return $this->get((string) $response->header('Location'))->body();
    }
}
