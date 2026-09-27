<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\ValidationException;
use GNesting\Enums\AdminRole;
use GNesting\Services\Shipping\ShippingCalculator;
use GNesting\Services\StoreConfig\ConfigExporter;
use GNesting\Services\StoreConfig\ConfigImporter;
use GNesting\Tests\Support\TestFiles;
use Symfony\Component\Yaml\Yaml;

/** Etapa 13: configuração da loja em YAML (loja, frete, categorias, materiais), exportar e importar. */
final class StoreConfigYamlTest extends HttpTestCase
{
    private function export(): string
    {
        return $this->container->get(ConfigExporter::class)->toYaml();
    }

    /** @return array<string, int> */
    private function import(string $yaml): array
    {
        return $this->container->get(ConfigImporter::class)->fromYaml($yaml);
    }

    /** @return array<string, string> caminho => mensagem */
    private function importErrors(string $yaml): array
    {
        try {
            $this->import($yaml);
            self::fail('A importação deveria ter sido recusada');
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    public function testExportIsReadableYamlWithTheWholeConfiguration(): void
    {
        $yaml = $this->export();
        self::assertStringStartsWith('# Configuração da loja G-Nesting', $yaml);
        $data = Yaml::parse($yaml);

        self::assertSame(['loja', 'frete', 'categorias', 'materiais'], array_keys($data));
        self::assertSame('BA', $data['frete']['uf_origem']);
        self::assertSame(['ate_1kg' => 19.9, 'por_kg_adicional' => 4.0, 'prazo_dias' => 4], $data['frete']['tabela']['local']['economico']);
        self::assertSame('relogios', $data['categorias'][0]['slug']);
        self::assertSame([], $data['categorias'][0]['subcategorias']);
        $material = $data['materiais'][0];
        self::assertSame(['MDF-AMD-06', 'chapa', 6.0], [$material['codigo'], $material['unidade'], $material['espessura_mm']]);
        self::assertArrayNotHasKey('saldo', $material, 'Saldo é operação, não configuração');
        self::assertStringNotContainsString('pedido', strtolower(implode(',', array_keys($data))));
    }

    public function testReimportingTheExportChangesNothing(): void
    {
        $before = $this->export();
        $summary = $this->import($before);

        self::assertSame(7, $summary['categorias atualizadas']);
        self::assertSame(3, $summary['materiais atualizados']);
        self::assertSame(Yaml::parse($before), Yaml::parse($this->export()));
    }

    public function testShippingTableFromYamlIsUsedByTheCheckoutAndOtherBandsStay(): void
    {
        $this->import(<<<'YAML'
            frete:
              frete_gratis_acima: 300
              retirada: { ativa: true, rotulo: Retirada na oficina, dias: 1 }
              tabela:
                SE:
                  economico: { ate_1kg: 25.5, prazo_dias: 6 }
            YAML);

        $options = $this->container->get(ShippingCalculator::class)->quote('01310-100', 800, 10000); // SP = região SE
        $byCode = array_column(array_map(fn ($o) => (array) $o, $options), null, 'code');
        self::assertSame(2550, $byCode['economico']['priceCents']);
        self::assertSame(6, $byCode['economico']['days']);
        self::assertSame(4990, $byCode['expresso']['priceCents'], 'Serviço fora do arquivo continua como estava');
        self::assertSame('Retirada na oficina', $byCode['retirada']['service']);

        $free = $this->container->get(ShippingCalculator::class)->quote('01310-100', 800, 30000);
        self::assertSame(0, array_column(array_map(fn ($o) => (array) $o, $free), 'priceCents', 'code')['economico']);

        $data = Yaml::parse($this->export());
        self::assertSame(19.9, $data['frete']['tabela']['local']['economico']['ate_1kg'], 'Faixa fora do arquivo continua como estava');
        self::assertSame(300.0, $data['frete']['frete_gratis_acima']);
    }

    public function testCategoriesAndMaterialsAreUpsertedAndNothingIsDeleted(): void
    {
        $this->db->pdo()->exec("UPDATE materials SET stock_qty = 12.5 WHERE code = 'MDF-AMD-06'");

        $summary = $this->import(<<<'YAML'
            categorias:
              - slug: relogios
                nome: Relógios de parede
                ativa: false
                subcategorias:
                  - { nome: Relógios grandes, slug: relogios-grandes, ordem: 5 }
              - { nome: Luminárias, slug: luminarias, descricao: Luminárias em MDF. }
            materiais:
              - { codigo: MDF-AMD-06, custo: 189.9 }
              - { codigo: acr-cri-03, nome: Acrílico cristal, espessura_mm: 3, unidade: chapa, custo: "249,00", estoque_minimo: 1 }
            YAML);

        self::assertSame(['categorias novas' => 2, 'categorias atualizadas' => 1, 'materiais novos' => 1, 'materiais atualizados' => 1], $summary);

        $relogios = $this->db->pdo()->query("SELECT id, name, is_active, description FROM categories WHERE slug = 'relogios'")->fetch();
        self::assertSame(['Relógios de parede', 0], [$relogios['name'], (int) $relogios['is_active']]);
        self::assertStringStartsWith('Relógios de parede com desenho', (string) $relogios['description'], 'Campo fora do arquivo fica como estava');
        self::assertSame((int) $relogios['id'], (int) $this->fetchValue("SELECT parent_id FROM categories WHERE slug = 'relogios-grandes'"));
        self::assertSame(9, (int) $this->fetchValue('SELECT COUNT(*) FROM categories WHERE deleted_at IS NULL'), '7 do seed + 2 novas: nenhuma apagada');

        $amd = $this->db->pdo()->query("SELECT cost_cents, stock_qty, name FROM materials WHERE code = 'MDF-AMD-06'")->fetch();
        self::assertSame([18990, '12.50', 'MDF amadeirado'], [(int) $amd['cost_cents'], $amd['stock_qty'], $amd['name']], 'Saldo nunca muda pelo arquivo');
        $acrylic = $this->db->pdo()->query("SELECT cost_cents, stock_qty, thickness_mm FROM materials WHERE code = 'ACR-CRI-03'")->fetch();
        self::assertSame([24900, '0.00', '3.00'], [(int) $acrylic['cost_cents'], $acrylic['stock_qty'], $acrylic['thickness_mm']]);

        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'store_config'"));
        self::assertGreaterThan(0, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'price_change' AND entity_type = 'material'"));
    }

    public function testAnyErrorRejectsTheWholeFileWithItsLocation(): void
    {
        $errors = $this->importErrors(<<<'YAML'
            loja:
              whatsap_numero: (71) 99999-8888
            frete:
              uf_origem: XX
              tabela:
                SE:
                  economico: { ate_1kg: -3 }
                Lua:
                  economico: { ate_1kg: 1 }
            categorias:
              - { nome: Nova válida, slug: nova-valida }
              - { nome: Slug ruim, slug: Slug Ruim }
              - { nome: Repetida, slug: nova-valida }
              - nome: Mãe
                slug: mae
                subcategorias:
                  - { nome: Filha, slug: filha, subcategorias: [] }
            materiais:
              - { codigo: MDF-NOVO, nome: Sem espessura, unidade: chapa }
              - { codigo: MDF-NOVO2, nome: Unidade ruim, espessura_mm: 3, unidade: litro }
            extra: 1
            YAML);

        foreach (['extra', 'loja.whatsap_numero', 'frete.uf_origem', 'frete.tabela.SE.economico.ate_1kg', 'frete.tabela.Lua',
            'categorias[1].slug', 'categorias[2].slug', 'categorias[3].subcategorias[0].subcategorias',
            'materiais[0].espessura_mm', 'materiais[1].unidade'] as $path) {
            self::assertArrayHasKey($path, $errors, $path . ' — erros: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
        }
        self::assertStringContainsString('dois níveis', $errors['categorias[3].subcategorias[0].subcategorias']);

        self::assertFalse($this->fetchValue("SELECT 1 FROM categories WHERE slug = 'nova-valida'"), 'Nada foi gravado');
        self::assertSame(29.9, Yaml::parse($this->export())['frete']['tabela']['SE']['economico']['ate_1kg'], 'Frete intacto');

        self::assertArrayHasKey('arquivo', $this->importErrors("loja: [aberto\n"));
        self::assertArrayHasKey('arquivo', $this->importErrors("- só uma lista\n"));
    }

    public function testStoreSettingsKeepWhatIsMissingAndReuseTheFormRules(): void
    {
        $this->import("loja:\n  faixa_avisos: Frete grátis acima de R$ 300\n");
        $store = Yaml::parse($this->export())['loja'];
        self::assertSame('Frete grátis acima de R$ 300', $store['faixa_avisos']);
        self::assertTrue($store['whatsapp_botao_flutuante'], 'Botão ligado continua ligado');

        $this->import("loja:\n  whatsapp_botao_flutuante: false\n");
        $this->import("loja:\n  faixa_avisos: ''\n");
        self::assertFalse(Yaml::parse($this->export())['loja']['whatsapp_botao_flutuante'], 'Botão desligado continua desligado');

        $errors = $this->importErrors("loja:\n  whatsapp_numero: '123'\n");
        self::assertArrayHasKey('loja.whatsapp_numero', $errors);
    }

    public function testOwnerExportsAndImportsThroughThePanel(): void
    {
        $this->loginAdmin(AdminRole::Owner);

        $download = $this->get('/admin/configuracoes/exportar');
        self::assertSame(200, $download->status());
        self::assertStringStartsWith('application/yaml', (string) $download->header('Content-Type'));
        self::assertStringContainsString('attachment; filename="gnesting-configuracao-', (string) $download->header('Content-Disposition'));
        self::assertStringContainsString('tabela:', $download->body());

        $file = TestFiles::raw('config.yaml', "categorias:\n  - { nome: Luminárias, slug: luminarias }\n");
        $this->post('/admin/configuracoes/importar', [], ['arquivo' => [$file]]);
        self::assertStringContainsString('Configuração importada: 1 categorias novas', $this->get('/admin/configuracoes')->body());

        $bad = TestFiles::raw('config.yaml', "categorias:\n  - { nome: Sem slug }\n");
        $this->post('/admin/configuracoes/importar', [], ['arquivo' => [$bad]]);
        self::assertStringContainsString('Nada foi importado. Corrija o arquivo: categorias[0].slug', html_entity_decode($this->get('/admin/configuracoes')->body()));

        $notYaml = TestFiles::raw('config.txt', 'loja: {}');
        $this->post('/admin/configuracoes/importar', [], ['arquivo' => [$notYaml]]);
        self::assertStringContainsString('Envie um arquivo .yaml', html_entity_decode($this->get('/admin/configuracoes')->body()));
    }
}
