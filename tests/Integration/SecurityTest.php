<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\Router;
use GNesting\Enums\AdminRole;

/**
 * Checklist de segurança (docs/05 §15), verificado pela porta da frente.
 * Os testes que varrem rotas leem o Router: rota nova já nasce coberta.
 */
final class SecurityTest extends HttpTestCase
{
    /** Valores de exemplo para os parâmetros das rotas, pelo nome. */
    private const PARAM_SAMPLES = [
        'numero' => 'GN-2026-000001', 'referencia' => 'SIM-0123456789abcdef', 'slug' => 'x',
    ];

    /** @return list<array{methods: list<string>, path: string, middleware: list<string>}> */
    private function routes(): array
    {
        return $this->container->get(Router::class)->routes();
    }

    private function concrete(string $path): string
    {
        return (string) preg_replace_callback('/\{(\w+)(?::([^{}]*(?:\{[^}]*\}[^{}]*)*))?\}/', function (array $m): string {
            if (isset(self::PARAM_SAMPLES[$m[1]])) {
                return self::PARAM_SAMPLES[$m[1]];
            }

            return ($m[2] ?? '') === '[a-f0-9]{64}' ? str_repeat('a', 64) : '1';
        }, $path);
    }

    // ---- CSRF, autenticação e papéis em todas as rotas ------------------------------

    public function testEveryPostRouteRejectsRequestsWithoutCsrfToken(): void
    {
        $checked = 0;
        foreach ($this->routes() as $route) {
            if (!in_array('POST', $route['methods'], true) || str_starts_with($route['path'], '/webhooks/')) {
                continue;
            }
            $this->newBrowser();
            $path = $this->concrete($route['path']);
            self::assertSame(419, $this->postRaw($path, ['_token' => 'forjado'])->status(), "POST {$path} com token falso");
            self::assertSame(419, $this->postRaw($path)->status(), "POST {$path} sem token");
            $checked++;
        }
        self::assertGreaterThan(60, $checked, 'Varreu as rotas de verdade');
    }

    public function testEveryAdminRouteRequiresLogin(): void
    {
        $public = ['/admin/login', '/admin/recuperar-senha', '/admin/redefinir-senha/{token:[a-f0-9]{64}}'];
        foreach ($this->routes() as $route) {
            if (!str_starts_with($route['path'], '/admin') || in_array($route['path'], $public, true)) {
                continue;
            }
            $this->newBrowser();
            $path = $this->concrete($route['path']);
            $response = in_array('GET', $route['methods'], true) ? $this->get($path) : $this->post($path);
            self::assertSame('/admin/login', $response->header('Location'), "{$route['methods'][0]} {$path} sem login");
        }
    }

    public function testEveryRoleRestrictedRouteForbidsOtherRoles(): void
    {
        $browsers = [];
        foreach ([AdminRole::Manager, AdminRole::Production, AdminRole::Support] as $role) {
            $this->loginAdmin($role);
            $browsers[$role->value] = $this->container;
        }

        $checked = 0;
        foreach ($this->routes() as $route) {
            $allowed = null;
            foreach ($route['middleware'] as $middleware) {
                if (str_starts_with($middleware, 'role:')) {
                    $allowed = explode(',', substr($middleware, 5));
                }
            }
            if ($allowed === null) {
                continue;
            }
            foreach ($browsers as $role => $container) {
                if (in_array($role, $allowed, true)) {
                    continue;
                }
                $this->container = $container;
                $path = $this->concrete($route['path']);
                $response = in_array('GET', $route['methods'], true) ? $this->get($path) : $this->post($path);
                self::assertSame(403, $response->status(), "{$role} em {$route['methods'][0]} {$path}");
                $checked++;
            }
        }
        self::assertGreaterThan(100, $checked);
    }

    public function testCustomerAreaRequiresLogin(): void
    {
        foreach (['/conta', '/conta/pedidos'] as $path) {
            self::assertSame('/entrar', $this->get($path)->header('Location'), $path);
        }
    }

    // ---- Injeção de SQL ----------------------------------------------------------------

    public function testSqlInjectionAttemptsAreHarmless(): void
    {
        $payloads = ["' OR '1'='1", "1; DROP TABLE products; --", "\" OR 1=1 --", "1' UNION SELECT password_hash FROM users --", '%', '_'];
        foreach ($payloads as $payload) {
            $search = $this->get('/busca', ['q' => $payload]);
            self::assertSame(200, $search->status(), $payload);
            self::assertStringNotContainsString('$argon2', $search->body());
            self::assertStringNotContainsString('$2y$', $search->body());

            foreach (['ordem' => $payload, 'preco_min' => $payload, 'preco_max' => $payload, 'pagina' => $payload] as $param => $value) {
                self::assertSame(200, $this->get('/produtos', [$param => $value])->status(), "{$param}={$payload}");
            }
            self::assertSame(404, $this->get('/produto/' . rawurlencode($payload))->status());

            $this->get('/entrar');
            $login = $this->post('/entrar', ['email' => $payload . '@x.test', 'password' => $payload]);
            self::assertSame('/entrar', $login->header('Location'), 'Login recusado, sem erro de SQL');
        }
        self::assertGreaterThan(0, (int) $this->fetchValue('SELECT COUNT(*) FROM products'), 'Tabela intacta');
        self::assertSame(0, (int) $this->fetchValue("SELECT COUNT(*) FROM products WHERE name LIKE '%DROP%'"));
    }

    // ---- XSS armazenado ------------------------------------------------------------------

    public function testStoredXssIsEscapedInStoreAdminAndProduction(): void
    {
        $script = '<script>alert(1)</script>';
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE products SET name = ?, short_description = ? WHERE slug = 'relogio-geometrico-g-nesting'")
            ->execute(["Relógio {$script}", "Descrição \"><img src=x onerror=alert(2)>"]);

        $pages = [$this->get('/produto/relogio-geometrico-g-nesting')->body(), $this->get('/produtos')->body(), $this->get('/busca', ['q' => 'Relógio'])->body()];
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        $pages[] = $this->get('/carrinho')->body();

        // Nome e endereço do comprador são texto livre
        $this->post('/checkout', $this->checkoutForm(['name' => "Ana {$script}", 'street' => 'Rua "><svg onload=alert(3)>', 'complement' => "' onmouseover='alert(4)"]));
        $order = $this->lastOrder();
        self::assertSame("Ana {$script}", $order['customer_name'], 'Gravado como digitado (escapar é na saída)');
        $pages[] = $this->get('/pedido/' . $order['number'] . '/confirmacao')->body();

        $this->loginAdmin(AdminRole::Manager);
        $pages[] = $this->get('/admin/pedidos')->body();
        $pages[] = $this->get('/admin/pedidos/' . $order['id'])->body();
        $pages[] = $this->get('/admin/clientes')->body();
        $pages[] = $this->get('/admin/clientes/' . $order['customer_id'])->body();
        $pages[] = $this->get('/admin/produtos')->body();

        foreach ($pages as $n => $html) {
            self::assertStringContainsString('</html>', $html, "Página {$n} renderizou");
            self::assertStringNotContainsString($script, $html, "Página {$n}");
            self::assertStringNotContainsString('<img src=x', $html, "Página {$n}");
            self::assertStringNotContainsString('<svg onload', $html, "Página {$n}");
            self::assertStringNotContainsString("' onmouseover='", $html, "Página {$n}");
        }
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $pages[0]);
        self::assertStringContainsString('Ana &lt;script&gt;', $pages[6], 'Página do pedido no painel mostra o nome escapado');

        // JSON-LD do produto: o nome não fecha a tag <script>
        preg_match_all('#<script type="application/ld\+json">(.+?)</script>#s', $pages[0], $blocks);
        self::assertSame('Relógio ' . $script, json_decode($blocks[1][0], true)['name']);
    }

    public function testPersonalizationRejectsMarkup(): void
    {
        $pdo = $this->db->pdo();
        $productId = (int) $this->fetchValue("SELECT id FROM products WHERE slug = 'relogio-geometrico-g-nesting'");
        $pdo->exec("INSERT INTO personalization_rules (product_id, field_key, label, type, is_required, min_length, max_length, charset)
                    VALUES ({$productId}, 'nome', 'Nome', 'text', 1, 1, 30, 'text_basic')");
        $ruleId = (int) $pdo->lastInsertId();

        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1', "pers_{$ruleId}" => '<b>Ana</b>']);
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM cart_items'));

        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1', "pers_{$ruleId}" => "Ana & Zé's (casa)"]);
        $cart = $this->get('/carrinho')->body();
        self::assertStringContainsString('Ana &amp; Zé&#039;s (casa)', $cart);
    }

    // ---- Preço, posse e redirecionamento ------------------------------------------------

    public function testPricesComeFromTheServerNotFromTheForm(): void
    {
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1',
            'price_cents' => '1', 'unit_price_cents' => '1', 'price' => '0,01']);
        $this->post('/checkout', $this->checkoutForm() + ['total_cents' => '1', 'subtotal_cents' => '1', 'shipping_cents' => '0',
            'discount_cents' => '99999', 'shipping_price' => '0']);

        $order = $this->lastOrder();
        self::assertSame(12990, (int) $order['subtotal_cents']);
        self::assertSame(0, (int) $order['discount_cents']);
        self::assertSame(12990 + (int) $order['shipping_cents'], (int) $order['total_cents']);
        self::assertGreaterThan(0, (int) $order['shipping_cents']);
    }

    public function testOrderPagesAreOnlyVisibleToTheirOwner(): void
    {
        $this->post('/carrinho/itens', ['variant_id' => (string) $this->referenceVariant(), 'quantity' => '1']);
        $this->post('/checkout', $this->checkoutForm());
        $number = $this->lastOrder()['number'];
        self::assertSame(200, $this->get("/pedido/{$number}/confirmacao")->status(), 'Mesmo navegador');

        $this->newBrowser();
        self::assertSame(404, $this->get("/pedido/{$number}/confirmacao")->status(), 'Outro navegador sem a chave');
        self::assertSame(404, $this->get("/pedido/{$number}/confirmacao", ['chave' => str_repeat('0', 64)])->status(), 'Chave errada');
        self::assertSame(404, $this->get("/pedido/{$number}/confirmacao", ['chave' => "' OR 1=1 --"])->status());
        self::assertSame(419, $this->postRaw("/pedido/{$number}/pagar")->status());

        $this->registerCustomer('outro@cliente.test');
        self::assertStringNotContainsString($number, $this->get('/conta/pedidos')->body());
        self::assertSame(404, $this->get("/pedido/{$number}/confirmacao")->status(), 'Outro cliente logado');
    }

    public function testLoginNeverRedirectsToAnotherSite(): void
    {
        $this->registerCustomer('redir@cliente.test');
        $this->post('/sair');
        foreach (['https://evil.test/x', '//evil.test', '/\\evil.test', 'javascript:alert(1)', "/conta\r\nLocation: https://evil.test"] as $target) {
            $this->get('/entrar', ['voltar' => $target]);
            $location = (string) $this->post('/entrar', ['email' => 'redir@cliente.test', 'password' => 'senha-do-cliente'])->header('Location');
            self::assertSame('/conta', $location, $target);
            $this->post('/sair');
        }
    }

    // ---- Cabeçalhos, erros e arquivos ---------------------------------------------------

    public function testSecurityHeadersOnEveryKindOfResponse(): void
    {
        $responses = [
            'página' => $this->get('/'),
            'redirecionamento' => $this->get('/conta'),
            '404' => $this->get('/nao-existe'),
            '419' => $this->postRaw('/carrinho/itens'),
            'xml' => $this->get('/sitemap.xml'),
            'texto' => $this->get('/robots.txt'),
            'json' => $this->post('/api/frete/cotar', ['cep' => '01310-100']),
        ];
        foreach ($responses as $kind => $response) {
            $kind = (string) $kind;
            self::assertStringContainsString("script-src 'self'", (string) $response->header('Content-Security-Policy'), $kind);
            self::assertStringNotContainsString('unsafe-inline', (string) $response->header('Content-Security-Policy'), $kind);
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'), $kind);
            self::assertSame('SAMEORIGIN', $response->header('X-Frame-Options'), $kind);
            self::assertNotNull($response->header('Referrer-Policy'), $kind);
        }
    }

    public function testWebServerConfigurationBlocksSensitivePaths(): void
    {
        $root = dirname(__DIR__, 2);
        $rootHtaccess = (string) file_get_contents($root . '/.htaccess');
        self::assertStringContainsString('RewriteRule ^(app|bin|config|database|docs|routes|storage|tests|vendor)(/|$) - [F,L]', $rootHtaccess);
        self::assertStringContainsString('RewriteRule (^|/)\.(?!well-known/) - [F,L]', $rootHtaccess, 'Arquivos ocultos (.env, .git)');
        self::assertStringContainsString('Require all denied', (string) file_get_contents($root . '/storage/.htaccess'));

        $public = (string) file_get_contents($root . '/public/.htaccess');
        self::assertStringContainsString('RewriteRule ^uploads/.*\.(php\d?|phtml|phar)$ - [F,L]', $public);
        self::assertMatchesRegularExpression('/<FilesMatch "\\\\\.\(php\\\\d\?\|phtml\|phar\)\$">\s*Require all denied/', $public);

        // O único PHP em public/ é o front controller; nada de .env ou backups ali
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/public', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $name = $file->getFilename();
            if (preg_match('/\.(php\d?|phtml|phar)$/', $name) === 1) {
                self::assertSame('index.php', $name, (string) $file);
            }
            self::assertDoesNotMatchRegularExpression('/^\.env|\.(sql|bak|log|zip)$/', $name, (string) $file);
        }

        // .env fora do Git
        self::assertMatchesRegularExpression('/^\/?\.env$/m', (string) file_get_contents($root . '/.gitignore'));
    }
}
