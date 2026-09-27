<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Database;
use GNesting\Repositories\ProductRepository;
use GNesting\Repositories\ProductVariantRepository;
use PDO;

/**
 * "Duplicar produto": copia cadastro, variações (com opções), personalização e fichas de produção.
 *
 * A cópia nasce inativa, com estoque zerado, SKUs e endereço novos e sem fotos: arquivo de imagem
 * compartilhado entre dois produtos sumiria dos dois quando um deles fosse apagado. Pelo mesmo motivo
 * não copia arquivos de produção nem a imagem das opções de personalização.
 */
final class ProductDuplicator
{
    private const PRODUCT_COLUMNS = ['category_id', 'short_description', 'description', 'highlights', 'keywords', 'care_instructions',
        'assembly_info', 'production_lead_days', 'dispatch_days', 'personalization_enabled', 'is_featured', 'is_new', 'meta_title', 'meta_description'];
    private const VARIANT_COLUMNS = ['name', 'price_cents', 'compare_at_price_cents', 'cost_cents', 'material_label', 'finish_label',
        'width_mm', 'height_mm', 'depth_mm', 'weight_g', 'package_width_mm', 'package_height_mm', 'package_length_mm', 'package_weight_g',
        'is_default', 'is_active', 'sort_order'];
    private const RULE_COLUMNS = ['field_key', 'label', 'help_text', 'type', 'is_required', 'min_length', 'max_length', 'charset',
        'max_size_mm', 'price_delta_cents', 'sort_order', 'is_active'];
    private const SPEC_COLUMNS = ['material_id', 'thickness_mm', 'cut_width_mm', 'cut_height_mm', 'pieces_per_sheet', 'sheet_yield_percent',
        'cnc_program_ref', 'finish_notes', 'internal_notes'];
    private const STEP_COLUMNS = ['stage', 'description', 'tool', 'operations_count', 'estimated_minutes', 'is_passive', 'sort_order'];

    private PDO $pdo;

    public function __construct(
        private readonly Database $db,
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
        private readonly AuditService $audit,
    ) {
        $this->pdo = $db->pdo();
    }

    /** @return int id da cópia */
    public function duplicate(int $productId): int
    {
        return $this->db->transaction(function () use ($productId): int {
            $source = $this->row('SELECT * FROM products WHERE id = ? AND deleted_at IS NULL', [$productId])
                ?? throw new BusinessRuleException('Produto não encontrado.');

            $name = mb_substr((string) $source['name'], 0, 141) . ' (cópia)';
            $slug = SlugGenerator::unique($name, fn (string $s): bool => $this->products->slugExists($s), 170);
            $newId = $this->insert('products', ['name' => $name, 'slug' => $slug, 'is_active' => 0] + $this->pick($source, self::PRODUCT_COLUMNS));

            // Opções e valores (Acabamento: Natural, Preto…), com o mapa de ids antigos → novos
            $valueMap = [];
            foreach ($this->rows('SELECT * FROM product_options WHERE product_id = ? ORDER BY sort_order, id', [$productId]) as $option) {
                $optionId = $this->insert('product_options', ['product_id' => $newId, 'name' => $option['name'], 'sort_order' => $option['sort_order']]);
                foreach ($this->rows('SELECT * FROM product_option_values WHERE option_id = ? ORDER BY sort_order, id', [$option['id']]) as $value) {
                    $valueMap[(int) $value['id']] = $this->insert('product_option_values', [
                        'option_id' => $optionId, 'value' => $value['value'], 'sort_order' => $value['sort_order'],
                    ]);
                }
            }

            $variants = $this->rows('SELECT * FROM product_variants WHERE product_id = ? AND deleted_at IS NULL ORDER BY is_default DESC, sort_order, id', [$productId]);
            foreach ($variants as $variant) {
                $variantId = $this->insert('product_variants', [
                    'product_id' => $newId, 'sku' => $this->newSku((string) $variant['sku']),
                ] + $this->pick($variant, self::VARIANT_COLUMNS));

                $inventory = $this->row('SELECT stock_mode, reorder_level FROM inventory WHERE variant_id = ?', [$variant['id']]);
                $this->insert('inventory', [
                    'variant_id' => $variantId, 'stock_mode' => $inventory['stock_mode'] ?? 'made_to_order',
                    'quantity_on_hand' => 0, 'quantity_reserved' => 0, 'reorder_level' => $inventory['reorder_level'] ?? null,
                ]);
                foreach ($this->rows('SELECT option_value_id FROM variant_option_values WHERE variant_id = ?', [$variant['id']]) as $link) {
                    if (isset($valueMap[(int) $link['option_value_id']])) {
                        $this->insert('variant_option_values', ['variant_id' => $variantId, 'option_value_id' => $valueMap[(int) $link['option_value_id']]]);
                    }
                }
                $spec = $this->row('SELECT * FROM production_specs WHERE variant_id = ?', [$variant['id']]);
                if ($spec !== null) {
                    $specId = $this->insert('production_specs', ['variant_id' => $variantId] + $this->pick($spec, self::SPEC_COLUMNS));
                    foreach ($this->rows('SELECT * FROM production_spec_steps WHERE spec_id = ? ORDER BY sort_order, id', [$spec['id']]) as $step) {
                        $this->insert('production_spec_steps', ['spec_id' => $specId] + $this->pick($step, self::STEP_COLUMNS));
                    }
                }
            }

            foreach ($this->rows('SELECT * FROM personalization_rules WHERE product_id = ? ORDER BY sort_order, id', [$productId]) as $rule) {
                $ruleId = $this->insert('personalization_rules', ['product_id' => $newId] + $this->pick($rule, self::RULE_COLUMNS));
                foreach ($this->rows('SELECT * FROM personalization_values WHERE rule_id = ? ORDER BY sort_order, id', [$rule['id']]) as $value) {
                    $this->insert('personalization_values', [
                        'rule_id' => $ruleId, 'code' => $value['code'], 'label' => $value['label'], 'image_path' => null,
                        'price_delta_cents' => $value['price_delta_cents'], 'sort_order' => $value['sort_order'], 'is_active' => $value['is_active'],
                    ]);
                }
            }

            $this->audit->record(AuditService::CREATE, 'product', $newId, null, [
                'copia_de' => $productId, 'name' => $name, 'slug' => $slug, 'variacoes' => count($variants),
            ]);

            return $newId;
        });
    }

    /** SKU da cópia: ORIGINAL-C, ORIGINAL-C2… (único para sempre, até 40 caracteres) */
    private function newSku(string $sku): string
    {
        for ($n = 1; ; $n++) {
            $suffix = '-C' . ($n > 1 ? $n : '');
            $candidate = mb_substr($sku, 0, 40 - strlen($suffix)) . $suffix;
            if (!$this->variants->skuExists($candidate, null)) {
                return $candidate;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $columns
     * @return array<string, mixed>
     */
    private function pick(array $row, array $columns): array
    {
        return array_intersect_key($row, array_flip($columns));
    }

    /** @param array<string, mixed> $data */
    private function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        ))->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $params): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
