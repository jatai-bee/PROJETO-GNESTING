<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\Bootstrap;
use GNesting\Core\Config;
use GNesting\Core\Csrf;
use GNesting\Core\Database;
use GNesting\Core\Kernel;
use GNesting\Core\Logger;
use GNesting\Core\Maintenance;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Enums\AdminRole;
use GNesting\Services\AdminUserService;
use GNesting\Services\Mail\LogMailer;
use GNesting\Services\Mail\Mailer;
use GNesting\Tests\Support\TestFiles;

/**
 * Testes pela porta da frente (Kernel), simulando navegadores.
 * Cada newBrowser() é um navegador novo (sessão e carrinho próprios) sobre o mesmo banco.
 * Todos os navegadores do teste compartilham a mesma caixa de e-mail ($this->mail).
 */
abstract class HttpTestCase extends IntegrationTestCase
{
    protected LogMailer $mail;
    protected string $ip = '192.0.2.150';
    /** @var array<string, mixed> configuração aplicada a todo navegador novo (chave com pontos => valor) */
    protected array $configOverrides = [];
    /** Arquivo do modo manutenção deste teste (nunca o storage/ real) */
    protected string $maintenanceFile;
    /** @var array<string, string> cookies recebidos (como um navegador) */
    private array $cookieJar = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new LogMailer(TestFiles::tempDir('gn-mail'));
        $this->maintenanceFile = TestFiles::tempDir('gn-maint') . '/maintenance.json';
        $this->newBrowser();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestFiles::cleanup();
    }

    protected function newBrowser(): void
    {
        $this->cookieJar = [];
        $container = Bootstrap::createContainer(dirname(__DIR__, 2));
        foreach ($this->configOverrides as $key => $value) {
            $container->get(Config::class)->set($key, $value);
        }
        $container->instance(Maintenance::class, new Maintenance($this->maintenanceFile));
        $container->instance(Database::class, $this->db);
        $container->set(Logger::class, fn () => new Logger(sys_get_temp_dir() . '/gnesting-test-logs'));
        $container->instance(Mailer::class, $this->mail);
        $this->container = $container;
    }

    /** Guarda o navegador atual (sessão + cookies) para voltar a ele com useBrowser(). @return array{0: \GNesting\Core\Container, 1: array<string, string>} */
    protected function currentBrowser(): array
    {
        return [$this->container, $this->cookieJar];
    }

    /** @param array{0: \GNesting\Core\Container, 1: array<string, string>} $browser */
    protected function useBrowser(array $browser): void
    {
        [$this->container, $this->cookieJar] = $browser;
    }

    /** @param array<string, string> $query */
    protected function get(string $path, array $query = []): Response
    {
        return $this->send(new Request('GET', $path, $query, [], $this->cookies(), ['REMOTE_ADDR' => $this->ip]));
    }

    /** POST com o token CSRF da sessão atual. @param array<string, mixed> $body */
    protected function post(string $path, array $body = []): Response
    {
        return $this->postRaw($path, $body + ['_token' => $this->container->get(Csrf::class)->token()]);
    }

    /** POST sem acrescentar nada (ex.: sem token). @param array<string, mixed> $body */
    protected function postRaw(string $path, array $body = []): Response
    {
        return $this->send(new Request('POST', $path, [], $body, $this->cookies(), ['REMOTE_ADDR' => $this->ip]));
    }

    protected function send(Request $request): Response
    {
        $response = $this->container->get(Kernel::class)->handle($request);
        foreach ($response->cookies() as $name => $cookie) {
            $this->cookieJar[$name] = $cookie['value'];
        }

        return $response;
    }

    /** Navegador novo, logado no painel com o papel pedido (cria o usuário se preciso). */
    protected function loginAdmin(AdminRole $role, string $password = 'senha-muito-segura'): string
    {
        $this->newBrowser();
        $email = $role->value . '@seguranca.test';
        if (!$this->fetchValue('SELECT 1 FROM users WHERE email = :e', ['e' => $email])) {
            $this->container->get(AdminUserService::class)->create('Equipe ' . $role->value, $email, $role, $password);
        }
        $this->get('/admin/login');
        $this->post('/admin/login', ['email' => $email, 'password' => $password]);

        return $email;
    }

    /** Navegador novo com um cliente cadastrado e logado. */
    protected function registerCustomer(string $email, string $password = 'senha-do-cliente'): void
    {
        $this->newBrowser();
        $this->get('/cadastro');
        $this->post('/cadastro', ['name' => 'Cliente Teste', 'email' => $email, 'password' => $password, 'password_confirmation' => $password]);
    }

    protected function referenceVariant(): int
    {
        return (int) $this->fetchValue("SELECT id FROM product_variants WHERE sku = 'REL-GEO-001'");
    }

    /** @return array<string, string> */
    protected function checkoutForm(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Ana Souza', 'email' => 'ana@cliente.test', 'cpf' => '529.982.247-25', 'phone' => '(71) 99999-8888',
            'zip_code' => '01310-100', 'street' => 'Av. Paulista', 'number' => '1000', 'complement' => '', 'district' => 'Bela Vista',
            'city' => 'São Paulo', 'state' => 'SP', 'recipient_name' => '', 'shipping_code' => 'economico', 'quoted_zip' => '01310100',
        ];
    }

    /** @return array<string, mixed> */
    protected function lastOrder(): array
    {
        return $this->db->pdo()->query('SELECT * FROM orders ORDER BY id DESC LIMIT 1')->fetch();
    }

    /** @return array<string, string> */
    protected function cookies(): array
    {
        return $this->cookieJar;
    }
}
