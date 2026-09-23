<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\Csrf;
use GNesting\Core\Kernel;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Enums\AdminRole;
use GNesting\Services\AdminUserService;
use GNesting\Services\ImageProcessor;
use GNesting\Tests\Support\TestFiles;

/**
 * Fluxos do painel pelo pipeline HTTP completo: permissões por papel,
 * cadastro de produto, upload de imagem e ativação.
 */
final class AdminPanelHttpTest extends IntegrationTestCase
{
    private Kernel $kernel;
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = TestFiles::tempDir('gn-http-uploads');
        $this->container->set(ImageProcessor::class, fn () => new ImageProcessor($this->uploads, 5 * 1024 * 1024));
        $this->kernel = $this->container->get(Kernel::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestFiles::cleanup();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @param array<string, string> $query
     */
    private function request(string $method, string $path, array $body = [], array $files = [], array $query = []): Response
    {
        return $this->kernel->handle(new Request($method, $path, $query, $body, [], [
            'REMOTE_ADDR' => '192.0.2.20', 'HTTP_USER_AGENT' => 'PHPUnit',
        ], $files));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     */
    private function post(string $path, array $body = [], array $files = []): Response
    {
        return $this->request('POST', $path, $body + ['_token' => $this->container->get(Csrf::class)->token()], $files);
    }

    private function loginAs(AdminRole $role): void
    {
        $email = $role->value . '@painel.test';
        $this->container->get(AdminUserService::class)->create('Equipe ' . $role->label(), $email, $role, 'senha-muito-segura');
        $this->request('GET', '/admin/login');
        self::assertSame('/admin', $this->post('/admin/login', ['email' => $email, 'password' => 'senha-muito-segura'])->header('Location'));
    }

    /** @return array<string, string> */
    private function productForm(array $overrides = []): array
    {
        $categoryId = (string) $this->fetchValue("SELECT id FROM categories WHERE slug = 'decoracao'");

        return $overrides + [
            'name' => 'Painel Ondas', 'slug' => '', 'category_id' => $categoryId, 'short_description' => 'Painel decorativo',
            'description' => 'Descrição longa', 'highlights' => "Recorte CNC\nPronto para pendurar", 'production_lead_days' => '5',
            'sku' => 'pai-ond-001', 'price' => '189,90', 'compare_at_price' => '', 'material_label' => 'MDF 6 mm',
            'finish_label' => 'Natural', 'width_mm' => '600', 'height_mm' => '400', 'depth_mm' => '', 'weight_g' => '1200',
            'package_width_mm' => '', 'package_height_mm' => '', 'package_length_mm' => '', 'package_weight_g' => '',
            'stock_mode' => 'made_to_order', 'quantity_on_hand' => '0', 'meta_title' => '', 'meta_description' => '',
            'is_new' => '1',
        ];
    }

    public function testSupportRoleCannotManageCatalog(): void
    {
        $this->loginAs(AdminRole::Support);

        self::assertSame(403, $this->request('GET', '/admin/produtos')->status());
        self::assertSame(403, $this->request('GET', '/admin/categorias')->status());
        self::assertSame(403, $this->post('/admin/produtos/novo', $this->productForm())->status());

        $dashboard = $this->request('GET', '/admin')->body();
        self::assertStringNotContainsString('href="/admin/produtos"', $dashboard, 'Menu oculta módulos sem permissão');
    }

    public function testManagerCannotManageUsersOrSeeAudit(): void
    {
        $this->loginAs(AdminRole::Manager);

        self::assertSame(200, $this->request('GET', '/admin/produtos')->status());
        self::assertSame(403, $this->request('GET', '/admin/usuarios')->status());
        self::assertSame(403, $this->request('GET', '/admin/logs')->status());
    }

    public function testCreateProductUploadImageAndActivate(): void
    {
        $this->loginAs(AdminRole::Manager);

        $created = $this->post('/admin/produtos/novo', $this->productForm());
        self::assertSame(303, $created->status());
        self::assertMatchesRegularExpression('#^/admin/produtos/(\d+)/imagens$#', (string) $created->header('Location'));
        $id = (int) preg_replace('#\D#', '', (string) $created->header('Location'));

        // Não ativa sem imagem
        $this->post("/admin/produtos/{$id}/status", ['active' => '1']);
        self::assertStringContainsString('pelo menos uma imagem', $this->request('GET', "/admin/produtos/{$id}/editar")->body());

        // Upload pelo formulário (multipart)
        $upload = $this->post("/admin/produtos/{$id}/imagens", ['alt_text' => 'Painel Ondas na sala'], [
            'images' => [TestFiles::image('painel.jpg', 1200, 900, 'jpeg')],
        ]);
        self::assertSame("/admin/produtos/{$id}/imagens", $upload->header('Location'));
        $page = $this->request('GET', "/admin/produtos/{$id}/imagens")->body();
        self::assertStringContainsString('1 imagem enviada', $page);
        self::assertStringContainsString('alt="Painel Ondas na sala"', $page);

        // Agora ativa
        $this->post("/admin/produtos/{$id}/status", ['active' => '1']);
        self::assertSame(1, (int) $this->fetchValue('SELECT is_active FROM products WHERE id = :id', ['id' => $id]));

        $list = $this->request('GET', '/admin/produtos', query: ['q' => 'PAI-OND'])->body();
        self::assertStringContainsString('Painel Ondas', $list);
        self::assertStringContainsString('R$ 189,90', $list);
    }

    public function testInvalidProductFormReturnsWithErrorsAndInput(): void
    {
        $this->loginAs(AdminRole::Manager);

        $response = $this->post('/admin/produtos/novo', $this->productForm(['price' => '12,345', 'sku' => 'REL-GEO-001', 'name' => 'Nome <mantido>']));
        self::assertSame('/admin/produtos/novo', $response->header('Location'));

        $form = $this->request('GET', '/admin/produtos/novo')->body();
        self::assertStringContainsString('deve ser um valor em reais', $form);
        self::assertStringContainsString('value="Nome &lt;mantido&gt;"', $form);
        self::assertStringContainsString('checked', $form, 'Checkbox marcado é preservado');
    }

    public function testDuplicateSkuFromServiceKeepsFormInput(): void
    {
        $this->loginAs(AdminRole::Manager);

        $this->post('/admin/produtos/novo', $this->productForm(['sku' => 'REL-GEO-001', 'name' => 'Relógio Cópia']));
        $form = $this->request('GET', '/admin/produtos/novo')->body();

        self::assertStringContainsString('Este SKU já foi usado', $form);
        self::assertStringContainsString('value="Relógio Cópia"', $form);
    }

    public function testOwnerManagesUsersAndSeesAuditLog(): void
    {
        $this->loginAs(AdminRole::Owner);

        $response = $this->post('/admin/usuarios/novo', [
            'name' => 'Nova Produção', 'email' => 'nova@painel.test', 'role' => 'production',
            'password' => 'senha-muito-segura', 'password_confirmation' => 'senha-muito-segura',
        ]);
        self::assertSame('/admin/usuarios', $response->header('Location'));
        self::assertStringContainsString('nova@painel.test', $this->request('GET', '/admin/usuarios')->body());

        $audit = $this->request('GET', '/admin/logs', query: ['acao' => 'create'])->body();
        self::assertStringContainsString('Usuário do painel', $audit);
        self::assertStringNotContainsString('senha-muito-segura', $audit);
    }

    public function testCategoryCrudThroughPanel(): void
    {
        $this->loginAs(AdminRole::Manager);

        $this->post('/admin/categorias/novo', [
            'name' => 'Luminárias', 'slug' => '', 'parent_id' => '', 'description' => '', 'sort_order' => '80',
            'is_active' => '1', 'meta_title' => '', 'meta_description' => '',
        ]);
        $id = (int) $this->fetchValue("SELECT id FROM categories WHERE slug = 'luminarias'");
        self::assertGreaterThan(0, $id);

        $delete = $this->post("/admin/categorias/{$id}/excluir");
        self::assertSame('/admin/categorias', $delete->header('Location'));
        self::assertStringContainsString('excluída', $this->request('GET', '/admin/categorias')->body());

        $relogios = (int) $this->fetchValue("SELECT id FROM categories WHERE slug = 'relogios'");
        $this->post("/admin/categorias/{$relogios}/excluir");
        self::assertStringContainsString('Mova os produtos', $this->request('GET', '/admin/categorias')->body());
    }
}
