<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/** Regras de personalização controlada e suas opções (tipo "select"). */
final class PersonalizationRepository extends Repository
{
    private const RULE_FIELDS = 'id, product_id, field_key, label, help_text, type, is_required, min_length, max_length,
        charset, max_size_mm, price_delta_cents, sort_order, is_active';

    /**
     * Regras do produto com 'values'. $onlyActive = visão da loja (regras e opções ativas).
     *
     * @return list<array<string, mixed>>
     */
    public function rulesForProduct(int $productId, bool $onlyActive): array
    {
        $rules = $this->fetchAll(
            'SELECT ' . self::RULE_FIELDS . ' FROM personalization_rules
              WHERE product_id = :id' . ($onlyActive ? ' AND is_active = 1' : '') . '
              ORDER BY sort_order, id',
            ['id' => $productId]
        );
        if ($rules === []) {
            return [];
        }

        $values = $this->fetchAll(
            'SELECT pv.id, pv.rule_id, pv.code, pv.label, pv.price_delta_cents, pv.sort_order, pv.is_active
               FROM personalization_values pv
               JOIN personalization_rules r ON r.id = pv.rule_id
              WHERE r.product_id = :id' . ($onlyActive ? ' AND pv.is_active = 1' : '') . '
              ORDER BY pv.sort_order, pv.id',
            ['id' => $productId]
        );
        foreach ($rules as &$rule) {
            $rule['values'] = array_values(array_filter(
                $values,
                static fn (array $v): bool => (int) $v['rule_id'] === (int) $rule['id']
            ));
        }

        return $rules;
    }

    /** @return array<string, mixed>|null regra com 'values' (todas, inclusive inativas) */
    public function find(int $productId, int $ruleId): ?array
    {
        $rule = $this->fetchOne(
            'SELECT ' . self::RULE_FIELDS . ' FROM personalization_rules WHERE id = :id AND product_id = :product_id',
            ['id' => $ruleId, 'product_id' => $productId]
        );
        if ($rule !== null) {
            $rule['values'] = $this->values($ruleId);
        }

        return $rule;
    }

    /** @return list<array<string, mixed>> */
    public function values(int $ruleId): array
    {
        return $this->fetchAll(
            'SELECT id, code, label, price_delta_cents, sort_order, is_active FROM personalization_values
              WHERE rule_id = :id ORDER BY sort_order, id',
            ['id' => $ruleId]
        );
    }

    public function keyExists(int $productId, string $key): bool
    {
        return $this->fetchValue(
            'SELECT 1 FROM personalization_rules WHERE product_id = :id AND field_key = :key',
            ['id' => $productId, 'key' => $key]
        ) !== null;
    }

    public function countRules(int $productId): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM personalization_rules WHERE product_id = :id', ['id' => $productId]);
    }

    /** @param array<string, mixed> $data */
    public function create(int $productId, array $data): int
    {
        return $this->insert(
            'INSERT INTO personalization_rules (product_id, field_key, label, help_text, type, is_required, min_length, max_length,
                                                charset, max_size_mm, price_delta_cents, sort_order, is_active)
             VALUES (:product_id, :field_key, :label, :help_text, :type, :is_required, :min_length, :max_length,
                     :charset, :max_size_mm, :price_delta_cents, :sort_order, :is_active)',
            $data + ['product_id' => $productId]
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $ruleId, array $data): void
    {
        unset($data['field_key']); // a chave é permanente (vai para a produção e para os pedidos)
        $this->execute(
            'UPDATE personalization_rules
                SET label = :label, help_text = :help_text, type = :type, is_required = :is_required,
                    min_length = :min_length, max_length = :max_length, charset = :charset, max_size_mm = :max_size_mm,
                    price_delta_cents = :price_delta_cents, sort_order = :sort_order, is_active = :is_active
              WHERE id = :id',
            $data + ['id' => $ruleId]
        );
    }

    public function delete(int $ruleId): void
    {
        $this->execute('DELETE FROM personalization_rules WHERE id = :id', ['id' => $ruleId]);
    }

    public function createValue(int $ruleId, string $code, string $label, int $priceDelta, int $sortOrder): void
    {
        $this->execute(
            'INSERT INTO personalization_values (rule_id, code, label, price_delta_cents, sort_order, is_active)
             VALUES (:rule_id, :code, :label, :delta, :sort, 1)',
            ['rule_id' => $ruleId, 'code' => $code, 'label' => $label, 'delta' => $priceDelta, 'sort' => $sortOrder]
        );
    }

    public function updateValue(int $valueId, string $label, int $priceDelta, int $sortOrder, bool $active): void
    {
        $this->execute(
            'UPDATE personalization_values SET label = :label, price_delta_cents = :delta, sort_order = :sort, is_active = :active
              WHERE id = :id',
            ['label' => $label, 'delta' => $priceDelta, 'sort' => $sortOrder, 'active' => (int) $active, 'id' => $valueId]
        );
    }

    /** Mantém products.personalization_enabled coerente com a existência de regras ativas. */
    public function syncProductFlag(int $productId): void
    {
        $this->execute(
            'UPDATE products SET personalization_enabled =
                EXISTS (SELECT 1 FROM personalization_rules r WHERE r.product_id = :id_rules AND r.is_active = 1)
              WHERE id = :id',
            ['id_rules' => $productId, 'id' => $productId]
        );
    }
}
