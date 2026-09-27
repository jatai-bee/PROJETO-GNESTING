<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\AuditContext;
use GNesting\Core\Database;
use GNesting\Core\ValidationException;
use GNesting\Repositories\InventoryRepository;
use GNesting\Repositories\ProductOptionRepository;
use GNesting\Repositories\ProductRepository;
use GNesting\Repositories\ProductVariantRepository;

/**
 * Variações pré-cadastradas: opções (eixos) → valores → combinações (variantes com SKU próprio).
 *
 * Regras:
 * - até 3 opções por produto, 10 valores por opção e 50 variantes;
 * - a combinação de uma variante é fixa; para mudar, exclui-se a variante e gera-se outra;
 * - ao criar uma opção, as variantes existentes recebem o primeiro valor dela;
 * - uma opção só pode ser excluída quando tem um único valor (senão combinações colidiriam);
 * - valor em uso por variante não pode ser excluído;
 * - a variante padrão não pode ser desativada nem excluída (troque o padrão antes);
 * - o nome da variante ("Natural / 45 cm") é recalculado a partir dos valores.
 */
final class VariantService
{
    public const MAX_OPTIONS = 3;
    public const MAX_VALUES = 10;
    public const MAX_VARIANTS = 50;

    private const COPY_FIELDS = [
        'price_cents', 'compare_at_price_cents', 'cost_cents', 'material_label', 'finish_label',
        'width_mm', 'height_mm', 'depth_mm', 'weight_g',
        'package_width_mm', 'package_height_mm', 'package_length_mm', 'package_weight_g',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
        private readonly ProductOptionRepository $options,
        private readonly InventoryRepository $inventory,
        private readonly AuditService $audit,
        private readonly AuditContext $auditContext,
    ) {
    }

    /**
     * Cria uma opção com seus valores ("Natural, Preto" ou um por linha).
     *
     * @throws BusinessRuleException
     */
    public function addOption(int $productId, string $name, string $valuesText): void
    {
        $name = $this->cleanLabel($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw new BusinessRuleException('Informe o nome da opção (até 60 caracteres). Ex.: Acabamento.');
        }
        $values = $this->parseValues($valuesText);

        $this->db->transaction(function () use ($productId, $name, $values): void {
            $this->assertProduct($productId);
            if ($this->options->countOptions($productId) >= self::MAX_OPTIONS) {
                throw new BusinessRuleException('Limite de ' . self::MAX_OPTIONS . ' opções por produto.');
            }
            if ($this->options->optionNameExists($productId, $name)) {
                throw new BusinessRuleException("Já existe a opção \"{$name}\".");
            }

            $optionId = $this->options->createOption($productId, $name);
            $firstValueId = null;
            foreach ($values as $value) {
                $valueId = $this->options->createValue($optionId, $value);
                $firstValueId ??= $valueId;
            }
            foreach ($this->variants->listByProduct($productId) as $variant) {
                $this->options->assignValue((int) $variant['id'], $firstValueId);
            }
            $this->refreshNames($productId);

            $this->audit->record(AuditService::UPDATE, 'product', $productId, null, ['option_added' => $name, 'values' => implode(', ', $values)]);
        });
    }

    /** @throws BusinessRuleException */
    public function addValue(int $productId, int $optionId, string $value): void
    {
        $value = $this->cleanLabel($value);
        if ($value === '' || mb_strlen($value) > 60) {
            throw new BusinessRuleException('Informe o valor (até 60 caracteres).');
        }

        $this->db->transaction(function () use ($productId, $optionId, $value): void {
            $option = $this->options->findOption($productId, $optionId) ?? throw new BusinessRuleException('Opção não encontrada.');
            if (count($this->options->values($optionId)) >= self::MAX_VALUES) {
                throw new BusinessRuleException('Limite de ' . self::MAX_VALUES . ' valores por opção.');
            }
            if ($this->options->valueExists($optionId, $value)) {
                throw new BusinessRuleException("\"{$value}\" já existe em {$option['name']}.");
            }
            $this->options->createValue($optionId, $value);
            $this->audit->record(AuditService::UPDATE, 'product', $productId, null, ['option_value_added' => "{$option['name']}: {$value}"]);
        });
    }

    /** @throws BusinessRuleException */
    public function deleteValue(int $productId, int $optionId, int $valueId): void
    {
        $this->db->transaction(function () use ($productId, $optionId, $valueId): void {
            $option = $this->options->findOption($productId, $optionId) ?? throw new BusinessRuleException('Opção não encontrada.');
            $value = $this->options->findValue($optionId, $valueId) ?? throw new BusinessRuleException('Valor não encontrado.');
            if ($this->options->valueUsage($valueId) > 0) {
                throw new BusinessRuleException("\"{$value['value']}\" está em uso por variações. Exclua essas variações antes.");
            }
            if (count($this->options->values($optionId)) <= 1) {
                throw new BusinessRuleException('A opção precisa de ao menos um valor. Para removê-la, exclua a opção.');
            }
            $this->options->deleteValue($valueId);
            $this->audit->record(AuditService::UPDATE, 'product', $productId, ['option_value' => "{$option['name']}: {$value['value']}"], null);
        });
    }

    /** @throws BusinessRuleException */
    public function deleteOption(int $productId, int $optionId): void
    {
        $this->db->transaction(function () use ($productId, $optionId): void {
            $option = $this->options->findOption($productId, $optionId) ?? throw new BusinessRuleException('Opção não encontrada.');
            $values = $this->options->values($optionId);
            if (count($values) > 1) {
                throw new BusinessRuleException(
                    "Para excluir \"{$option['name']}\", deixe-a com um único valor (exclua as variações e os demais valores antes)."
                );
            }
            foreach ($values as $value) {
                $this->options->deleteValue((int) $value['id']);
            }
            $this->options->deleteOption($optionId);
            $this->refreshNames($productId);
            $this->audit->record(AuditService::UPDATE, 'product', $productId, ['option_removed' => $option['name']], null);
        });
    }

    /**
     * Cria as combinações que ainda não existem, copiando preço, rótulos e medidas
     * da variante padrão. SKU = SKU padrão + sufixo dos valores (ex.: REL-GEO-001-PRE-45).
     *
     * @return int quantidade criada
     * @throws BusinessRuleException
     */
    public function generateCombinations(int $productId): int
    {
        return $this->db->transaction(function () use ($productId): int {
            $this->assertProduct($productId);
            $options = $this->options->optionsWithValues($productId);
            if ($options === []) {
                throw new BusinessRuleException('Cadastre ao menos uma opção (ex.: Acabamento) antes de gerar as variações.');
            }

            $existing = array_map(fn (array $combo): string => $this->comboKey($combo), $this->options->combinations($productId));
            $combos = [[]];
            foreach ($options as $option) {
                $next = [];
                foreach ($combos as $combo) {
                    foreach ($option['values'] as $value) {
                        $next[] = $combo + [(int) $option['id'] => $value];
                    }
                }
                $combos = $next;
            }
            $missing = array_values(array_filter(
                $combos,
                fn (array $combo): bool => !in_array($this->comboKey(array_map(static fn ($v) => (int) $v['id'], $combo)), $existing, true)
            ));
            if ($missing === []) {
                return 0;
            }
            if ($this->variants->countByProduct($productId) + count($missing) > self::MAX_VARIANTS) {
                throw new BusinessRuleException('Seriam ultrapassadas ' . self::MAX_VARIANTS . ' variações. Reduza os valores das opções.');
            }

            $default = $this->defaultVariant($productId);
            $base = array_intersect_key($default, array_flip(self::COPY_FIELDS));
            $created = [];
            foreach ($missing as $combo) {
                $sku = $this->uniqueSku((string) $default['sku'], array_map(static fn (array $v): string => (string) $v['value'], $combo));
                $variantId = $this->variants->create($productId, $base + [
                    'sku' => $sku,
                    'name' => implode(' / ', array_map(static fn (array $v): string => (string) $v['value'], $combo)),
                ]);
                foreach ($combo as $value) {
                    $this->options->assignValue($variantId, (int) $value['id']);
                }
                $this->inventory->create($variantId, (string) $default['stock_mode'], 0);
                $created[] = $sku;
            }

            $this->audit->record(AuditService::CREATE, 'product_variant', $productId, null, ['skus' => implode(', ', $created)]);

            return count($created);
        });
    }

    /**
     * @param array<string, mixed> $input sku, price_cents, compare_at_price_cents, material_label, finish_label,
     *                                    medidas, stock_mode, quantity_on_hand, is_active
     * @throws ValidationException|BusinessRuleException
     */
    public function updateVariant(int $productId, int $variantId, array $input): void
    {
        $this->db->transaction(function () use ($productId, $variantId, $input): void {
            $current = $this->variants->find($productId, $variantId) ?? throw new BusinessRuleException('Variação não encontrada.');
            $input += ['cost_cents' => $current['cost_cents']]; // sem o campo, o custo fica como está

            $errors = [];
            $sku = strtoupper((string) $input['sku']);
            if ($this->variants->skuExists($sku, $variantId)) {
                $errors['sku'] = 'Este SKU já foi usado (inclusive por produtos excluídos). Escolha outro.';
            }
            if ($input['compare_at_price_cents'] !== null && $input['compare_at_price_cents'] <= $input['price_cents']) {
                $errors['compare_at_price'] = 'O preço "de" precisa ser maior que o preço de venda.';
            }
            if ((bool) $current['is_default'] && !$input['is_active']) {
                $errors['is_active'] = 'A variação padrão não pode ser desativada. Escolha outra como padrão antes.';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }

            $data = array_intersect_key($input, array_flip(self::COPY_FIELDS)) + ['sku' => $sku];
            foreach (['material_label', 'finish_label'] as $field) {
                $data[$field] = ($data[$field] ?? '') === '' ? null : $data[$field];
            }
            $this->variants->update($variantId, $data);
            $this->variants->setActive($variantId, (bool) $input['is_active']);

            $priceFields = ['price_cents', 'compare_at_price_cents'];
            $this->audit->recordChanges(
                AuditService::PRICE_CHANGE, 'product_variant', $variantId,
                array_intersect_key($current, array_flip($priceFields)),
                array_intersect_key($data, array_flip($priceFields)),
            );
            $this->audit->recordChanges(
                AuditService::UPDATE, 'product_variant', $variantId,
                $current,
                array_diff_key($data, array_flip($priceFields)) + ['is_active' => (int) $input['is_active']],
            );

            $this->updateStock($variantId, $current, (string) $input['stock_mode'], (int) $input['quantity_on_hand']);
        });
    }

    /** @throws BusinessRuleException */
    public function setDefault(int $productId, int $variantId): void
    {
        $this->db->transaction(function () use ($productId, $variantId): void {
            $variant = $this->variants->find($productId, $variantId) ?? throw new BusinessRuleException('Variação não encontrada.');
            if (!(bool) $variant['is_active']) {
                throw new BusinessRuleException('Ative a variação antes de torná-la padrão.');
            }
            $this->variants->setDefault($productId, $variantId);
            $this->audit->record(AuditService::UPDATE, 'product', $productId, null, ['default_variant' => $variant['sku']]);
        });
    }

    /** @throws BusinessRuleException */
    public function deleteVariant(int $productId, int $variantId): void
    {
        $this->db->transaction(function () use ($productId, $variantId): void {
            $variant = $this->variants->find($productId, $variantId) ?? throw new BusinessRuleException('Variação não encontrada.');
            if ((bool) $variant['is_default']) {
                throw new BusinessRuleException('A variação padrão não pode ser excluída. Escolha outra como padrão antes.');
            }
            $this->variants->softDelete($variantId);
            $this->options->unassignVariant($variantId);
            $this->audit->record(AuditService::DELETE, 'product_variant', $variantId, ['sku' => $variant['sku'], 'name' => $variant['name']], null);
        });
    }

    /** @param array<string, mixed> $current */
    private function updateStock(int $variantId, array $current, string $mode, int $quantity): void
    {
        $oldMode = (string) $current['stock_mode'];
        $oldQuantity = (int) $current['quantity_on_hand'];
        if ($mode === 'made_to_order') {
            $quantity = $oldQuantity;
        }
        if ((bool) $current['has_inventory'] && $mode === $oldMode && $quantity === $oldQuantity) {
            return;
        }

        if ((bool) $current['has_inventory']) {
            $this->inventory->update($variantId, $mode, $quantity);
        } else {
            $this->inventory->create($variantId, $mode, $quantity);
        }
        if ($quantity !== $oldQuantity) {
            $this->inventory->addMovement($variantId, 'adjust', $quantity - $oldQuantity, 'Ajuste manual no painel', $this->auditContext->userId());
        }
        $this->audit->record(
            AuditService::STOCK_CHANGE, 'product_variant', $variantId,
            ['stock_mode' => $oldMode, 'quantity_on_hand' => $oldQuantity],
            ['stock_mode' => $mode, 'quantity_on_hand' => $quantity],
        );
    }

    /** Recalcula o nome de todas as variantes a partir dos valores (na ordem das opções). */
    private function refreshNames(int $productId): void
    {
        $options = $this->options->optionsWithValues($productId);
        $labels = [];
        foreach ($options as $option) {
            foreach ($option['values'] as $value) {
                $labels[(int) $value['id']] = (string) $value['value'];
            }
        }
        $combinations = $this->options->combinations($productId);

        foreach ($this->variants->listByProduct($productId) as $variant) {
            $combo = $combinations[(int) $variant['id']] ?? [];
            $parts = [];
            foreach ($options as $option) {
                if (isset($combo[(int) $option['id']])) {
                    $parts[] = $labels[$combo[(int) $option['id']]];
                }
            }
            $this->variants->setName((int) $variant['id'], $parts === [] ? null : implode(' / ', $parts));
        }
    }

    /** @param array<int, int> $combo option_id => value_id */
    private function comboKey(array $combo): string
    {
        ksort($combo);

        return implode('-', $combo);
    }

    /** @param list<string> $values */
    private function uniqueSku(string $base, array $values): string
    {
        $suffix = implode('-', array_map(
            static fn (string $v): string => strtoupper(substr(str_replace('-', '', slugify($v)), 0, 4)) ?: 'X',
            $values
        ));
        $base = substr($base, 0, 40 - strlen($suffix) - 4);
        $sku = "{$base}-{$suffix}";
        for ($n = 2; $this->variants->skuExists($sku); $n++) {
            $sku = "{$base}-{$suffix}-{$n}";
        }

        return $sku;
    }

    /** @return list<string> */
    private function parseValues(string $text): array
    {
        $values = [];
        foreach (preg_split('/[,\n]/', $text) ?: [] as $raw) {
            $value = $this->cleanLabel($raw);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > 60) {
                throw new BusinessRuleException("\"{$value}\" passa de 60 caracteres.");
            }
            $key = mb_strtolower($value);
            $values[$key] ??= $value;
        }
        if ($values === []) {
            throw new BusinessRuleException('Informe ao menos um valor. Ex.: Natural, Preto.');
        }
        if (count($values) > self::MAX_VALUES) {
            throw new BusinessRuleException('Limite de ' . self::MAX_VALUES . ' valores por opção.');
        }

        return array_values($values);
    }

    private function cleanLabel(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function assertProduct(int $productId): void
    {
        if ($this->products->findForAdmin($productId) === null) {
            throw new BusinessRuleException('Produto não encontrado.');
        }
    }

    /** @return array<string, mixed> */
    private function defaultVariant(int $productId): array
    {
        foreach ($this->variants->listByProduct($productId) as $variant) {
            if ((bool) $variant['is_default']) {
                return $variant;
            }
        }
        throw new BusinessRuleException('Produto sem variação padrão.');
    }
}
