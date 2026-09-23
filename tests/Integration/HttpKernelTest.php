<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\Csrf;
use GNesting\Core\Kernel;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Router;
use GNesting\Enums\AdminRole;
use GNesting\Services\Auth\AuthService;
use GNesting\Tests\Support\TestController;

/**
 * Pipeline HTTP completo (rota → middleware → controller → view), simulando
 * um navegador: a sessão em memória persiste entre as requisições do teste.
 */
final class HttpKernelTest extends IntegrationTestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->container->get(Router::class);
        $router->get('/__teste/ok-gestor', [TestController::class, 'ok'], ['admin', 'role:manager']);
        $router->get('/__teste/erro', [TestController::class, 'explode']);

        $this->kernel = $this->container->get(Kernel::class);
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, array $body = []): Response
    {
        return $this->kernel->handle(new Request($method, $path, [], $body, [], [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]));
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body = []): Response
    {
        return $this->request('POST', $path, $body + ['_token' => $this->container->get(Csrf::class)->token()]);
    }

    private function loginAdmin(AdminRole $role): void
    {
        $this->container->get(AuthService::class)->createAdmin('Equipe', 'equipe@gnesting.test', 'senha-admin-segura', $role);
        $this->request('GET', '/admin/login');
        $response = $this->post('/admin/login', ['email' => 'equipe@gnesting.test', 'password' => 'senha-admin-segura']);
        self::assertSame('/admin', $response->header('Location'));
    }

    public function testHomeRendersWithSecurityHeaders(): void
    {
        $response = $this->request('GET', '/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Objetos que <em>transformam</em> espaços.', $response->body());
        self::assertStringContainsString("default-src 'self'", (string) $response->header('Content-Security-Policy'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $this->request('GET', '/entrar');
        $response = $this->request('POST', '/entrar', ['email' => 'a@b.com', 'password' => 'x']);

        self::assertSame(419, $response->status());
    }

    public function testProtectedAreasRedirectToLogin(): void
    {
        self::assertSame('/admin/login', $this->request('GET', '/admin')->header('Location'));
        self::assertSame('/entrar', $this->request('GET', '/conta')->header('Location'));
    }

    public function testAdminLoginDashboardAndLogout(): void
    {
        $this->loginAdmin(AdminRole::Owner);

        $dashboard = $this->request('GET', '/admin');
        self::assertSame(200, $dashboard->status());
        self::assertStringContainsString('Proprietário', $dashboard->body());
        self::assertStringContainsString('Entrou no painel', $dashboard->body());

        self::assertSame('/admin/login', $this->post('/admin/sair')->header('Location'));
        self::assertSame('/admin/login', $this->request('GET', '/admin')->header('Location'));
    }

    public function testValidationErrorsReturnToFormWithOldInput(): void
    {
        $this->request('GET', '/cadastro');
        $response = $this->post('/cadastro', ['name' => 'Ana <b>', 'email' => 'invalido', 'password' => '1', 'password_confirmation' => '2']);
        self::assertSame(303, $response->status());
        self::assertSame('/cadastro', $response->header('Location'));

        $form = $this->request('GET', '/cadastro')->body();
        self::assertStringContainsString('não é um e-mail válido', $form);
        self::assertStringContainsString('value="Ana &lt;b&gt;"', $form);
        self::assertStringNotContainsString('value="1"', $form, 'Senha nunca volta para o formulário');
    }

    public function testRoleMiddlewareForbidsOtherRoles(): void
    {
        $this->loginAdmin(AdminRole::Support);
        self::assertSame(403, $this->request('GET', '/__teste/ok-gestor')->status());
    }

    public function testOwnerPassesAnyRoleRestriction(): void
    {
        $this->loginAdmin(AdminRole::Owner);
        self::assertSame(200, $this->request('GET', '/__teste/ok-gestor')->status());
    }

    public function testUnexpectedErrorShowsGenericPageWithoutDetails(): void
    {
        $response = $this->request('GET', '/__teste/erro');
        $body = $response->body();

        self::assertSame(500, $response->status());
        self::assertMatchesRegularExpression('/Código do erro: <strong>[0-9A-F]{6}<\/strong>/', $body);
        self::assertStringNotContainsString('senha=123', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('arquivo.php', $body);
    }

    public function testNotFoundPage(): void
    {
        $response = $this->request('GET', '/nao-existe');

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Página não encontrada', $response->body());
    }
}
