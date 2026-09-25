<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\ValidationException;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\PersonalizationRuleService;
use GNesting\Services\PersonalizationService;
use GNesting\Services\ProductService;
use GNesting\Services\VariantService;

/**
 * Regras de negócio da etapa 5: variações (opções → combinações → SKUs)
 * e personalização controlada (cadastro das regras e validação do que o cliente envia).
 */
final class VariantsAndPersonalizationTest extends IntegrationTestCase
{
    private VariantService $variants;
    private PersonalizationRuleService $rules;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->variants = $this->container->get(VariantService::class);
        $this->rules = $this->container->get(PersonalizationRuleService::class);
        $this->productId = $this->container->get(ProductService::class)->create([
            'category_id' => (int) $this->fetchValue("SELECT id FROM categories WHERE slug = 'relogios'"),
            'name' => 'Relógio Teste', 'slug' => '', 'short_description' => '', 'description' => '', 'highlights' => '',
            'production_lead_days' => 3, 'is_featured' => false, 'is_new' => false, 'meta_title' => '', 'meta_description' => '',
            'sku' => 'RT-001', 'price_cents' => 10000, 'compare_at_price_cents' => null, 'material_label' => 'MDF 6 mm',
            'finish_label' => '', 'width_mm' => 300, 'height_mm' => 300, 'depth_mm' => 6, 'weight_g' => 500,
            'package_width_mm' => null, 'package_height_mm' => null, 'package_length_mm' => null, 'package_weight_g' => null,
            'stock_mode' => 'stock', 'quantity_on_hand' => 5,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function variantRows(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, sku, name, price_cents, is_default, is_active FROM product_variants
              WHERE product_id = ? AND deleted_at IS NULL ORDER BY is_default DESC, id'
        );
        $statement->execute([$this->productId]);

        return $statement->fetchAll();
    }

    private function optionId(string $name): int
    {
        return (int) $this->fetchValue('SELECT id FROM product_options WHERE product_id = :p AND name = :n', ['p' => $this->productId, 'n' => $name]);
    }

    private function valueId(string $option, string $value): int
    {
        return (int) $this->fetchValue(
            'SELECT id FROM product_option_values WHERE option_id = :o AND value = :v',
            ['o' => $this->optionId($option), 'v' => $value]
        );
    }

    // ---- Variações -----------------------------------------------------------

    public function testOptionsGenerateCombinationsWithOwnSkus(): void
    {
        $this->variants->addOption($this->productId, 'Acabamento', 'Natural, Preto');
        self::assertSame('Natural', $this->variantRows()[0]['name'], 'Variação padrão recebe o primeiro valor');

        self::assertSame(1, $this->variants->generateCombinations($this->productId));
        $this->variants->addOption($this->productId, 'Tamanho', "30 cm\n45 cm");
        self::assertSame(2, $this->variants->generateCombinations($this->productId));
        self::assertSame(0, $this->variants->generateCombinations($this->productId), 'Não duplica combinações');

        $rows = $this->variantRows();
        self::assertCount(4, $rows);
        self::assertSame(['Natural / 30 cm', 'Preto / 30 cm', 'Natural / 45 cm', 'Preto / 45 cm'], array_column($rows, 'name'));
        self::assertSame('RT-001', $rows[0]['sku']);
        self::assertSame('RT-001-PRET-45CM', $rows[3]['sku']);
        self::assertSame([10000], array_values(array_unique(array_column($rows, 'price_cents'))), 'Preço copiado da padrão');

        // Nova variação herda o modo de estoque, mas começa sem quantidade
        self::assertSame('stock', $this->fetchValue('SELECT stock_mode FROM inventory WHERE variant_id = :v', ['v' => $rows[3]['id']]));
        self::assertSame(0, (int) $this->fetchValue('SELECT quantity_on_hand FROM inventory WHERE variant_id = :v', ['v' => $rows[3]['id']]));
    }

    public function testDuplicatesAndLimitsAreRejected(): void
    {
        $this->variants->addOption($this->productId, 'Acabamento', 'Natural, natural, Preto');
        self::assertSame(2, (int) $this->fetchValue('SELECT COUNT(*) FROM product_option_values WHERE option_id = :o', ['o' => $this->optionId('Acabamento')]));

        $this->expectBusinessRule(fn () => $this->variants->addOption($this->productId, 'acabamento', 'Branco'), 'Já existe');
        $this->expectBusinessRule(fn () => $this->variants->addValue($this->productId, $this->optionId('Acabamento'), 'PRETO'), 'já existe');
        $this->expectBusinessRule(fn () => $this->variants->addOption($this->productId, 'Cor', ' , '), 'ao menos um valor');

        $this->variants->addOption($this->productId, 'Tamanho', 'P');
        $this->variants->addOption($this->productId, 'Fonte', 'Serifada');
        $this->expectBusinessRule(fn () => $this->variants->addOption($this->productId, 'Moldura', 'Sim'), 'Limite de 3');
    }

    public function testValuesAndOptionsInUseCannotBeRemoved(): void
    {
        $this->variants->addOption($this->productId, 'Acabamento', 'Natural, Preto, Branco');
        $this->variants->generateCombinations($this->productId);
        $option = $this->optionId('Acabamento');

        $this->expectBusinessRule(fn () => $this->variants->deleteValue($this->productId, $option, $this->valueId('Acabamento', 'Preto')), 'em uso');
        $this->expectBusinessRule(fn () => $this->variants->deleteOption($this->productId, $option), 'único valor');

        // Excluindo as variações, os valores saem e a opção pode ser removida
        foreach ($this->variantRows() as $row) {
            if (!$row['is_default']) {
                $this->variants->deleteVariant($this->productId, (int) $row['id']);
            }
        }
        $this->variants->deleteValue($this->productId, $option, $this->valueId('Acabamento', 'Preto'));
        $this->variants->deleteValue($this->productId, $option, $this->valueId('Acabamento', 'Branco'));
        $this->variants->deleteOption($this->productId, $option);

        self::assertNull($this->variantRows()[0]['name'], 'Variação volta a ser única');
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM product_variants WHERE sku = 'RT-001-PRET'"), 'SKU excluído continua reservado');
    }

    public function testDefaultVariantRules(): void
    {
        $this->variants->addOption($this->productId, 'Acabamento', 'Natural, Preto');
        $this->variants->generateCombinations($this->productId);
        [$default, $black] = $this->variantRows();

        $this->expectBusinessRule(fn () => $this->variants->deleteVariant($this->productId, (int) $default['id']), 'padrão não pode ser excluída');

        $input = [
            'sku' => 'rt-001-preto', 'price_cents' => 12000, 'compare_at_price_cents' => null, 'material_label' => '', 'finish_label' => 'Preto',
            'width_mm' => 300, 'height_mm' => 300, 'depth_mm' => 6, 'weight_g' => 500, 'package_width_mm' => null,
            'package_height_mm' => null, 'package_length_mm' => null, 'package_weight_g' => null,
            'stock_mode' => 'stock', 'quantity_on_hand' => 2, 'is_active' => false,
        ];
        $this->variants->updateVariant($this->productId, (int) $black['id'], $input);
        self::assertSame('RT-001-PRETO', $this->fetchValue('SELECT sku FROM product_variants WHERE id = :id', ['id' => $black['id']]));
        self::assertSame(2, (int) $this->fetchValue("SELECT quantity FROM inventory_movements WHERE variant_id = :v AND type = 'adjust'", ['v' => $black['id']]));
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'price_change' AND entity_type = 'product_variant' AND entity_id = :id", ['id' => $black['id']]));

        $this->expectBusinessRule(fn () => $this->variants->setDefault($this->productId, (int) $black['id']), 'Ative a variação');

        $this->variants->updateVariant($this->productId, (int) $black['id'], ['is_active' => true] + $input);
        $this->variants->setDefault($this->productId, (int) $black['id']);
        self::assertSame('RT-001-PRETO', $this->fetchValue('SELECT sku FROM product_variants WHERE product_id = :p AND is_default = 1', ['p' => $this->productId]));

        try {
            $this->variants->updateVariant($this->productId, (int) $black['id'], ['is_active' => false] + $input);
            self::fail('Variação padrão não pode ser desativada');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('is_active', $e->errors());
        }
        try {
            $this->variants->updateVariant($this->productId, (int) $default['id'], ['sku' => 'REL-GEO-001'] + $input);
            self::fail('SKU duplicado');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('sku', $e->errors());
        }
    }

    // ---- Regras de personalização -------------------------------------------

    /** @return array<string, mixed> */
    private function rule(array $overrides = []): array
    {
        return $overrides + [
            'label' => 'Nome gravado', 'help_text' => '', 'type' => 'text', 'is_required' => false,
            'min_length' => null, 'max_length' => 20, 'charset' => 'letters_numbers', 'max_size_mm' => 180,
            'price_delta_cents' => 1500, 'sort_order' => 10, 'is_active' => true, 'values_text' => '',
        ];
    }

    public function testRuleCreationValidatesByTypeAndSyncsProductFlag(): void
    {
        $id = $this->rules->create($this->productId, $this->rule());
        self::assertSame('nome_gravado', $this->fetchValue('SELECT field_key FROM personalization_rules WHERE id = :id', ['id' => $id]));
        self::assertSame(1, (int) $this->fetchValue('SELECT personalization_enabled FROM products WHERE id = :id', ['id' => $this->productId]));
        $second = $this->rules->create($this->productId, $this->rule());
        self::assertSame('nome_gravado_2', $this->fetchValue('SELECT field_key FROM personalization_rules WHERE id = :id', ['id' => $second]));

        foreach ([
            [['max_length' => null], 'max_length'],
            [['max_length' => 500], 'max_length'],
            [['min_length' => 30], 'min_length'],
            [['charset' => 'regex-livre'], 'charset'],
            [['type' => 'initial', 'max_length' => 5], 'max_length'],
            [['type' => 'select', 'values_text' => ''], 'values_text'],
            [['type' => 'select', 'values_text' => "Clássica\nClássica"], 'values_text'],
            [['type' => 'select', 'values_text' => 'Clássica | dez reais'], 'values_text'],
            [['type' => 'upload'], 'type'],
        ] as [$override, $field]) {
            try {
                $this->rules->create($this->productId, $this->rule($override));
                self::fail('Deveria recusar: ' . json_encode($override));
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors(), json_encode($override));
            }
        }

        $this->rules->update($this->productId, $id, $this->rule(['is_active' => false]));
        $this->rules->update($this->productId, $second, $this->rule(['is_active' => false]));
        self::assertSame(0, (int) $this->fetchValue('SELECT personalization_enabled FROM products WHERE id = :id', ['id' => $this->productId]));
    }

    public function testSelectValuesRemovedFromListAreDeactivatedNotDeleted(): void
    {
        $id = $this->rules->create($this->productId, $this->rule([
            'label' => 'Fonte', 'type' => 'select', 'price_delta_cents' => 0,
            'values_text' => "Clássica\nManuscrita | 10,00\nStencil | 5",
        ]));
        $this->rules->update($this->productId, $id, $this->rule([
            'label' => 'Fonte', 'type' => 'select', 'price_delta_cents' => 0, 'values_text' => "Manuscrita | 12,00\nClássica",
        ]));

        $rows = $this->db->pdo()->query("SELECT code, price_delta_cents, is_active, sort_order FROM personalization_values WHERE rule_id = {$id} ORDER BY code")->fetchAll();
        self::assertSame(['classica', 'manuscrita', 'stencil'], array_column($rows, 'code'));
        self::assertSame([1, 1, 0], array_map('intval', array_column($rows, 'is_active')));
        self::assertSame(1200, (int) $rows[1]['price_delta_cents']);
        self::assertSame(10, (int) $rows[1]['sort_order'], 'Ordem segue a lista');
    }

    // ---- Validação do que o cliente envia -----------------------------------

    public function testCustomerInputIsValidatedAndPricedFromTheDatabase(): void
    {
        $text = $this->rules->create($this->productId, $this->rule());
        $initial = $this->rules->create($this->productId, $this->rule(['label' => 'Inicial', 'type' => 'initial', 'max_length' => 2, 'price_delta_cents' => 500]));
        $date = $this->rules->create($this->productId, $this->rule(['label' => 'Data especial', 'type' => 'date', 'price_delta_cents' => 0]));
        $font = $this->rules->create($this->productId, $this->rule([
            'label' => 'Fonte', 'type' => 'select', 'is_required' => true, 'price_delta_cents' => 200, 'values_text' => "Clássica\nManuscrita | 10,00",
        ]));
        $script = (int) $this->fetchValue("SELECT id FROM personalization_values WHERE rule_id = :r AND code = 'manuscrita'", ['r' => $font]);
        $service = $this->container->get(PersonalizationService::class);

        $result = $service->validate($this->productId, [
            $text => '  João   da Silva 2 ', $initial => 'a b', $date => '2026-12-25', $font => (string) $script, 999999 => 'ignorado',
        ]);
        $byRule = array_column($result['items'], null, 'rule_id');
        self::assertSame('João da Silva 2', $byRule[$text]['value_text'], 'Espaços normalizados, acentos aceitos');
        self::assertSame('AB', $byRule[$initial]['value_text']);
        self::assertSame('2026-12-25', $byRule[$date]['value_text']);
        self::assertSame('25/12/2026', $byRule[$date]['display']);
        self::assertSame($script, $byRule[$font]['value_id']);
        self::assertSame('Manuscrita', $byRule[$font]['display']);
        self::assertSame(1500 + 500 + 0 + 200 + 1000, $result['price_delta_cents']);
        self::assertCount(4, $result['items'], 'Campo desconhecido é ignorado');
        self::assertSame(64, strlen($result['hash']));

        // Data no formato brasileiro gera o mesmo resultado (mesmo hash)
        $same = $service->validate($this->productId, [$text => 'João da Silva 2', $initial => 'AB', $date => '25/12/2026', $font => (string) $script]);
        self::assertSame($result['hash'], $same['hash']);

        // Opcionais vazios: sem acréscimo; obrigatório continua exigido
        $minimal = $service->validate($this->productId, [$font => (string) $script]);
        self::assertSame(1200, $minimal['price_delta_cents']);
        self::assertNotSame($result['hash'], $minimal['hash']);

        foreach ([
            [[$font => ''], $font, 'Preencha'],
            [[$font => '0'], $font, 'Escolha uma das opções'],
            [[$font => (string) $script, $text => 'Ana ❤'], $text, 'aceita'],
            [[$font => (string) $script, $text => '<script>'], $text, 'aceita'],
            [[$font => (string) $script, $text => str_repeat('a', 21)], $text, 'até 20'],
            [[$font => (string) $script, $initial => 'ABC'], $initial, 'até 2 letras'],
            [[$font => (string) $script, $initial => '1'], $initial, 'até 2 letras'],
            [[$font => (string) $script, $date => '31/02/2026'], $date, 'data válida'],
            [[$font => (string) $script, $date => '1800-01-01'], $date, 'data válida'],
        ] as [$input, $ruleId, $message]) {
            try {
                $service->validate($this->productId, $input);
                self::fail('Deveria recusar: ' . json_encode($input, JSON_UNESCAPED_UNICODE));
            } catch (ValidationException $e) {
                self::assertStringContainsString($message, $e->errors()['pers_' . $ruleId] ?? '', json_encode($input, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    public function testInactiveOptionValueIsRejected(): void
    {
        $font = $this->rules->create($this->productId, $this->rule([
            'label' => 'Fonte', 'type' => 'select', 'values_text' => "Clássica\nStencil",
        ]));
        $stencil = (int) $this->fetchValue("SELECT id FROM personalization_values WHERE rule_id = :r AND code = 'stencil'", ['r' => $font]);
        $this->rules->update($this->productId, $font, $this->rule(['label' => 'Fonte', 'type' => 'select', 'values_text' => 'Clássica']));

        $this->expectException(ValidationException::class);
        (new PersonalizationService($this->container->get(\GNesting\Repositories\PersonalizationRepository::class)))
            ->validate($this->productId, [$font => (string) $stencil]);
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
