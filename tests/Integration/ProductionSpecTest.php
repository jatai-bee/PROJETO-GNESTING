<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\AuditContext;
use GNesting\Core\Csrf;
use GNesting\Core\Database;
use GNesting\Core\Kernel;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\ValidationException;
use GNesting\Enums\AdminRole;
use GNesting\Repositories\ProductionSpecRepository;
use GNesting\Services\AdminUserService;
use GNesting\Services\AuditService;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\MaterialService;
use GNesting\Services\ProductionFileService;
use GNesting\Services\ProductionSpecService;
use GNesting\Services\VariantService;
use GNesting\Tests\Support\TestFiles;

/**
 * Etapa 6: ficha de produção (material, corte, etapas e tempos), matéria-prima,
 * arquivos privados e acesso por papel. A ficha nunca aparece na loja.
 */
final class ProductionSpecTest extends IntegrationTestCase
{
    private ProductionSpecService $specs;
    private ProductionSpecRepository $repo;
    private string $storage;
    private int $productId;
    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = TestFiles::tempDir('gn-production-files');
        $this->container->set(ProductionFileService::class, fn ($c) => new ProductionFileService(
            $c->get(Database::class), $c->get(ProductionSpecRepository::class), $c->get(AuditService::class),
            $c->get(AuditContext::class), $this->storage, 1024 * 1024,
        ));
        $this->specs = $this->container->get(ProductionSpecService::class);
        $this->repo = $this->container->get(ProductionSpecRepository::class);
        $this->productId = (int) $this->fetchValue("SELECT id FROM products WHERE slug = 'relogio-geometrico-g-nesting'");
        $this->variantId = (int) $this->fetchValue("SELECT id FROM product_variants WHERE sku = 'REL-GEO-001'");
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestFiles::cleanup();
    }

    private function materialId(string $code): int
    {
        return (int) $this->fetchValue('SELECT id FROM materials WHERE code = :c', ['c' => $code]);
    }

    /** @return array<string, mixed> */
    private function specInput(array $overrides = []): array
    {
        return $overrides + [
            'material_id' => $this->materialId('MDF-AMD-06'), 'thickness_mm' => null, 'cut_width_mm' => 350, 'cut_height_mm' => 350,
            'pieces_per_sheet' => 28, 'sheet_yield_percent' => '82.50', 'cnc_program_ref' => 'CNC-REL-GEO-001-v2',
            'finish_notes' => 'Seladora fosca', 'internal_notes' => '',
        ];
    }

    /** @return array<string, string> */
    private function row(string $stage, string $minutes, array $extra = []): array
    {
        return $extra + ['position' => '', 'stage' => $stage, 'description' => '', 'tool' => '', 'operations' => '', 'minutes' => $minutes, 'passive' => '', 'remove' => ''];
    }

    // ---- Ficha e etapas --------------------------------------------------------

    public function testSeedSpecTimesAndCompleteness(): void
    {
        self::assertSame(['total' => 126, 'operator' => 66, 'passive' => 60], $this->repo->minutes($this->variantId), 'Secagem (60 min) é passiva');

        $spec = $this->repo->findByVariant($this->variantId);
        self::assertSame([], ProductionSpecService::missing($spec, 7, 126, 0), 'Referência de programa CNC basta');
        self::assertSame(['ficha não iniciada'], ProductionSpecService::missing(null, 0, 0, 0));
        self::assertSame(['material', 'etapas com tempo', 'programa CNC (referência ou arquivo)'],
            ProductionSpecService::missing(['material_id' => null, 'cnc_program_ref' => ''], 1, 0, 0));

        $this->db->pdo()->exec("UPDATE materials SET cost_cents = 10000 WHERE code = 'MDF-AMD-06'");
        self::assertSame(357, ProductionSpecService::materialCostPerPiece($this->repo->findByVariant($this->variantId)), 'R$ 100,00 ÷ 28 peças');
    }

    public function testSaveReplacesStepsIgnoresBlankRowsAndOrdersByPosition(): void
    {
        $this->specs->save($this->variantId, $this->specInput(), [
            $this->row('packaging', '5', ['position' => '30']),
            $this->row('cnc', '40', ['position' => '10', 'tool' => 'Fresa 1/8"', 'operations' => '3']),
            $this->row('', ''),                                                  // em branco: ignorada
            $this->row('drying', '90', ['position' => '20', 'passive' => '1']),
            $this->row('sanding', '10', ['remove' => '1']),                     // marcada para remover
        ]);

        $steps = $this->repo->steps((int) $this->repo->findByVariant($this->variantId)['id']);
        self::assertSame(['cnc', 'drying', 'packaging'], array_column($steps, 'stage'));
        self::assertSame(3, (int) $steps[0]['operations_count']);
        self::assertSame(['total' => 135, 'operator' => 45, 'passive' => 90], $this->repo->minutes($this->variantId));

        $spec = $this->repo->findByVariant($this->variantId);
        self::assertSame('6.00', (string) $spec['thickness_mm'], 'Espessura vazia assume a do material');
        self::assertSame(1, (int) $this->fetchValue(
            "SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'production_spec' AND action = 'update' AND entity_id = :id",
            ['id' => $spec['id']]
        ));
    }

    public function testInvalidInputIsRejectedWithFieldKeys(): void
    {
        foreach ([
            [[$this->row('laser', '10')], 'step_0_stage'],
            [[$this->row('cnc', '-5')], 'step_0_minutes'],
            [[$this->row('cnc', 'dez')], 'step_0_minutes'],
            [[$this->row('', '', ['description' => 'sem etapa'])], 'step_0_stage'],
            [[$this->row('cnc', '5'), $this->row('cnc', '5', ['operations' => 'x'])], 'step_1_operations'],
        ] as [$rows, $key]) {
            try {
                $this->specs->save($this->variantId, $this->specInput(), $rows);
                self::fail("Deveria recusar {$key}");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($key, $e->errors());
            }
        }

        $this->expectValidation(fn () => $this->specs->save($this->variantId, $this->specInput(['sheet_yield_percent' => '100.50']), []), 'sheet_yield_percent');
        $this->expectValidation(fn () => $this->specs->save($this->variantId, $this->specInput(['material_id' => 999999]), []), 'material_id');

        // Material inativo: não pode ser escolhido, mas continua na ficha que já o usa
        $this->db->pdo()->exec("UPDATE materials SET is_active = 0 WHERE code IN ('MDF-AMD-06', 'MDF-CRU-03')");
        $this->specs->save($this->variantId, $this->specInput(), [$this->row('cnc', '30')]);
        $this->expectValidation(fn () => $this->specs->save($this->variantId, $this->specInput(['material_id' => $this->materialId('MDF-CRU-03')]), []), 'material_id');

        self::assertSame(1, count($this->repo->steps((int) $this->repo->findByVariant($this->variantId)['id'])), 'Erro não altera a ficha (transação)');
    }

    public function testCopySpecToAnotherVariantWithoutFiles(): void
    {
        $variants = $this->container->get(VariantService::class);
        $variants->addOption($this->productId, 'Acabamento', 'Natural, Preto');
        $variants->generateCombinations($this->productId);
        $black = (int) $this->fetchValue("SELECT id FROM product_variants WHERE product_id = :p AND name = 'Preto'", ['p' => $this->productId]);

        $this->expectBusinessRule(fn () => $this->specs->copy($black, $this->variantId), 'ainda não tem ficha');
        $this->specs->copy($this->variantId, $black);

        self::assertSame($this->repo->minutes($this->variantId), $this->repo->minutes($black));
        self::assertSame('CNC-REL-GEO-001-v1', $this->repo->findByVariant($black)['cnc_program_ref']);
    }

    // ---- Materiais ----------------------------------------------------------------

    public function testMaterialsCodeIsUniqueAndUsedMaterialCannotBeDeleted(): void
    {
        $materials = $this->container->get(MaterialService::class);
        $input = [
            'code' => 'acr-cri-03', 'name' => 'Acrílico cristal', 'thickness_mm' => '3.00', 'sheet_width_mm' => 1000,
            'sheet_length_mm' => 2000, 'unit' => 'sheet', 'cost_cents' => 25000, 'stock_qty' => '4.00', 'reorder_level' => '2.00', 'is_active' => true,
        ];
        $id = $materials->create($input);
        self::assertSame('ACR-CRI-03', $this->fetchValue('SELECT code FROM materials WHERE id = :id', ['id' => $id]));
        $this->expectValidation(fn () => $materials->create($input), 'code');

        $materials->update($id, ['cost_cents' => 27000] + $input);
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'price_change' AND entity_type = 'material' AND entity_id = :id", ['id' => $id]));

        $materials->delete($id);
        self::assertFalse($this->fetchValue('SELECT 1 FROM materials WHERE id = :id', ['id' => $id]));
        $this->expectBusinessRule(fn () => $materials->delete($this->materialId('MDF-AMD-06')), 'Desative-o');
    }

    // ---- Arquivos -----------------------------------------------------------------

    public function testProductionFilesAreStoredPrivatelyWithChecksumAndVersions(): void
    {
        $files = $this->container->get(ProductionFileService::class);
        $gcode = "G21\nG90\nG0 X0 Y0\nM30\n";

        $first = $files->upload($this->variantId, TestFiles::raw('relogio.nc', $gcode), 'cnc');
        $second = $files->upload($this->variantId, TestFiles::raw('relogio.nc', $gcode . "; v2\n"), 'cnc');
        $files->upload($this->variantId, TestFiles::raw('C:\\fakepath\\../desenho.pdf', "%PDF-1.4\n%%EOF"), 'drawing');

        $rows = $this->db->pdo()->query('SELECT id, original_name, stored_path, version, checksum_sha256, size_bytes FROM production_files ORDER BY id')->fetchAll();
        self::assertSame(['relogio.nc', 'relogio.nc', 'desenho.pdf'], array_column($rows, 'original_name'), 'Caminho do nome original descartado');
        self::assertSame([1, 2, 1], array_map('intval', array_column($rows, 'version')));
        self::assertSame(hash('sha256', $gcode), $rows[0]['checksum_sha256']);
        self::assertMatchesRegularExpression('#^\d+/[a-f0-9]{32}\.nc$#', $rows[0]['stored_path'], 'Nome aleatório, sem o nome original');
        self::assertFileExists($this->storage . '/' . $rows[0]['stored_path']);

        $located = $files->locate($first, $this->productId);
        self::assertSame('relogio-v1.nc', $located['name']);
        self::assertNull($files->locate($first, $this->productId + 999), 'Arquivo não pertence a outro produto');

        foreach ([['shell.php', '<?php echo 1;'], ['virus.exe', 'MZ'], ['vazio.dxf', ''], ['grande.zip', str_repeat('a', 1024 * 1024 + 1)]] as [$name, $content]) {
            try {
                $files->upload($this->variantId, TestFiles::raw($name, $content), 'other');
                self::fail("Deveria recusar {$name}");
            } catch (BusinessRuleException) {
                self::assertSame(3, (int) $this->fetchValue('SELECT COUNT(*) FROM production_files'));
            }
        }

        $files->delete($this->productId, $second);
        self::assertFileDoesNotExist($this->storage . '/' . $rows[1]['stored_path']);
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'production_file' AND action = 'delete'"));
    }

    // ---- HTTP: papéis, formulário, download ------------------------------------

    private function kernel(): Kernel
    {
        return $this->container->get(Kernel::class);
    }

    /** @param array<string, mixed> $body */
    private function http(string $method, string $path, array $body = [], array $files = []): Response
    {
        if ($method === 'POST') {
            $body += ['_token' => $this->container->get(Csrf::class)->token()];
        }

        return $this->kernel()->handle(new Request($method, $path, [], $body, [], ['REMOTE_ADDR' => '192.0.2.60', 'HTTP_USER_AGENT' => 'PHPUnit'], $files));
    }

    private function loginAs(AdminRole $role): void
    {
        $email = $role->value . '@producao.test';
        $this->container->get(AdminUserService::class)->create('Equipe', $email, $role, 'senha-muito-segura');
        $this->http('GET', '/admin/login');
        // Destino: /admin ou a página pedida antes do login
        self::assertStringStartsWith('/admin', (string) $this->http('POST', '/admin/login', ['email' => $email, 'password' => 'senha-muito-segura'])->header('Location'));
    }

    public function testProductionRoleSeesOnlySpecsAndMaterials(): void
    {
        $this->loginAs(AdminRole::Production);
        $spec = "/admin/produtos/{$this->productId}/ficha-producao/{$this->variantId}";

        self::assertSame(200, $this->http('GET', '/admin/fichas')->status());
        self::assertSame(200, $this->http('GET', '/admin/materiais')->status());
        self::assertSame($spec, $this->http('GET', "/admin/produtos/{$this->productId}/ficha-producao")->header('Location'));

        $page = $this->http('GET', $spec)->body();
        self::assertStringContainsString('CNC-REL-GEO-001-v1', $page);
        self::assertStringContainsString('2 h 06 min', $page, 'Tempo total da ficha do seed');
        self::assertStringNotContainsString('/imagens"', $page, 'Aba sem acesso não aparece');

        self::assertSame(403, $this->http('GET', '/admin/produtos')->status());
        self::assertSame(403, $this->http('GET', "/admin/produtos/{$this->productId}/variantes")->status());
    }

    public function testSupportRoleAndGuestsCannotReachSpecsOrFiles(): void
    {
        $fileId = $this->container->get(ProductionFileService::class)->upload($this->variantId, TestFiles::raw('a.dxf', '0\nSECTION'), 'design');

        $guest = $this->http('GET', "/admin/arquivos-producao/{$fileId}");
        self::assertSame(303, $guest->status(), 'Visitante vai para o login');
        self::assertNull($guest->filePath());

        $this->loginAs(AdminRole::Support);
        self::assertSame(403, $this->http('GET', '/admin/fichas')->status());
        self::assertSame(403, $this->http('GET', "/admin/arquivos-producao/{$fileId}")->status());
        self::assertSame(403, $this->http('POST', '/admin/materiais/novo', ['code' => 'X'])->status());
    }

    public function testSpecFormRoundTripAndDownload(): void
    {
        $this->loginAs(AdminRole::Production);
        $path = "/admin/produtos/{$this->productId}/ficha-producao/{$this->variantId}";
        $form = [
            'material_id' => (string) $this->materialId('MDF-AMD-06'), 'thickness_mm' => '6', 'cut_width_mm' => '350', 'cut_height_mm' => '350',
            'pieces_per_sheet' => '28', 'sheet_yield_percent' => '82,5', 'cnc_program_ref' => 'CNC-REL-GEO-001-v3',
            'finish_notes' => 'Seladora', 'internal_notes' => 'Máquina 12 mm',
            'steps' => [
                ['position' => '10', 'stage' => 'cnc', 'description' => 'Recorte', 'tool' => 'Fresa', 'operations' => '2', 'minutes' => '30'],
                ['position' => '20', 'stage' => 'drying', 'description' => 'Secagem', 'minutes' => 'muito', 'passive' => '1'],
                ['position' => '', 'stage' => '', 'description' => '', 'minutes' => ''],
            ],
        ];

        $invalid = $this->http('POST', $path, $form);
        self::assertSame($path, $invalid->header('Location'));
        $back = $this->http('GET', $path)->body();
        self::assertStringContainsString('Minutos: número inteiro', $back);
        self::assertStringContainsString('value="muito"', $back, 'Linhas digitadas voltam ao formulário');
        self::assertSame(126, $this->repo->minutes($this->variantId)['total'], 'Nada foi salvo');

        $form['steps'][1]['minutes'] = '60';
        $this->http('POST', $path, $form);
        self::assertSame(['total' => 90, 'operator' => 30, 'passive' => 60], $this->repo->minutes($this->variantId));
        self::assertSame('82.50', (string) $this->repo->findByVariant($this->variantId)['sheet_yield_percent']);

        // Upload e download pelo painel
        $upload = $this->http('POST', "{$path}/arquivos", ['file_type' => 'cnc'], ['file' => [TestFiles::raw('relogio.nc', "G21\nM30\n")]]);
        self::assertSame($path, $upload->header('Location'));
        $fileId = (int) $this->fetchValue('SELECT id FROM production_files ORDER BY id DESC LIMIT 1');
        self::assertStringContainsString('relogio.nc', $this->http('GET', $path)->body());

        $download = $this->http('GET', "/admin/arquivos-producao/{$fileId}");
        self::assertSame(200, $download->status());
        self::assertSame('application/octet-stream', $download->header('Content-Type'));
        self::assertStringStartsWith('attachment; filename="relogio-v1.nc"', (string) $download->header('Content-Disposition'));
        self::assertSame("G21\nM30\n", file_get_contents((string) $download->filePath()));
        self::assertSame('nosniff', $download->header('X-Content-Type-Options'));

        self::assertSame(404, $this->http('GET', '/admin/arquivos-producao/999999')->status());
    }

    public function testStoreNeverShowsProductionData(): void
    {
        $page = $this->http('GET', '/produto/relogio-geometrico-g-nesting')->body();

        self::assertStringNotContainsString('CNC-REL-GEO-001', $page);
        self::assertStringNotContainsString('Máquina de relógio 12 mm eixo', $page, 'Observação interna do seed');
        self::assertStringNotContainsString('MDF-AMD-06', $page);
    }

    private function expectValidation(callable $action, string $field): void
    {
        try {
            $action();
            self::fail("Deveria recusar {$field}");
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors());
        }
    }

    private function expectBusinessRule(callable $action, string $message): void
    {
        try {
            $action();
            self::fail("Deveria recusar: {$message}");
        } catch (BusinessRuleException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
