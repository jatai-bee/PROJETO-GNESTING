<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/**
 * Eixos de variação (opções), seus valores e o vínculo variante ↔ valor.
 * "Em uso" considera só variantes não excluídas: ao excluir uma variante
 * o vínculo dela é removido (o pedido guarda o nome da variante como snapshot).
 */
final class ProductOptionRepository extends Repository
{
    /**
     * Opções do produto, cada uma com 'values' (id, value, sort_order, in_use).
     *
     * @return list<array<string, mixed>>
     */
    public function optionsWithValues(int $productId): array
    {
        $options = $this->fetchAll(
            'SELECT id, name, sort_order FROM product_options WHERE product_id = :id ORDER BY sort_order, id',
            ['id' => $productId]
        );
        if ($options === []) {
            return [];
        }

        $values = $this->fetchAll(
            'SELECT ov.id, ov.option_id, ov.value, ov.sort_order,
                    (SELECT COUNT(*) FROM variant_option_values vov
                       JOIN product_variants v ON v.id = vov.variant_id AND v.deleted_at IS NULL
                      WHERE vov.option_value_id = ov.id) AS in_use
               FROM product_option_values ov
               JOIN product_options o ON o.id = ov.option_id
              WHERE o.product_id = :id
              ORDER BY ov.sort_order, ov.id',
            ['id' => $productId]
        );

        foreach ($options as &$option) {
            $option['values'] = array_values(array_filter(
                $values,
                static fn (array $v): bool => (int) $v['option_id'] === (int) $option['id']
            ));
        }

        return $options;
    }

    /** @return array{id: int, name: string}|null */
    public function findOption(int $productId, int $optionId): ?array
    {
        return $this->fetchOne(
            'SELECT id, name FROM product_options WHERE id = :id AND product_id = :product_id',
            ['id' => $optionId, 'product_id' => $productId]
        );
    }

    public function countOptions(int $productId): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM product_options WHERE product_id = :id', ['id' => $productId]);
    }

    public function optionNameExists(int $productId, string $name): bool
    {
        return $this->fetchValue(
            'SELECT 1 FROM product_options WHERE product_id = :id AND name = :name',
            ['id' => $productId, 'name' => $name]
        ) !== null;
    }

    public function createOption(int $productId, string $name): int
    {
        return $this->insert(
            'INSERT INTO product_options (product_id, name, sort_order)
             SELECT :product_id, :name, COALESCE(MAX(sort_order), 0) + 10 FROM product_options WHERE product_id = :product_id2',
            ['product_id' => $productId, 'name' => $name, 'product_id2' => $productId]
        );
    }

    public function deleteOption(int $optionId): void
    {
        $this->execute('DELETE FROM product_options WHERE id = :id', ['id' => $optionId]);
    }

    /** @return array{id: int, value: string}|null */
    public function findValue(int $optionId, int $valueId): ?array
    {
        return $this->fetchOne(
            'SELECT id, value FROM product_option_values WHERE id = :id AND option_id = :option_id',
            ['id' => $valueId, 'option_id' => $optionId]
        );
    }

    /** @return list<array{id: int, value: string}> */
    public function values(int $optionId): array
    {
        return $this->fetchAll(
            'SELECT id, value FROM product_option_values WHERE option_id = :id ORDER BY sort_order, id',
            ['id' => $optionId]
        );
    }

    public function valueExists(int $optionId, string $value): bool
    {
        return $this->fetchValue(
            'SELECT 1 FROM product_option_values WHERE option_id = :id AND value = :value',
            ['id' => $optionId, 'value' => $value]
        ) !== null;
    }

    public function createValue(int $optionId, string $value): int
    {
        return $this->insert(
            'INSERT INTO product_option_values (option_id, value, sort_order)
             SELECT :option_id, :value, COALESCE(MAX(sort_order), 0) + 10 FROM product_option_values WHERE option_id = :option_id2',
            ['option_id' => $optionId, 'value' => $value, 'option_id2' => $optionId]
        );
    }

    /** Variantes não excluídas que usam o valor. */
    public function valueUsage(int $valueId): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM variant_option_values vov
               JOIN product_variants v ON v.id = vov.variant_id AND v.deleted_at IS NULL
              WHERE vov.option_value_id = :id',
            ['id' => $valueId]
        );
    }

    public function deleteValue(int $valueId): void
    {
        // Vínculos restantes só podem ser de variantes excluídas (valueUsage = 0)
        $this->execute('DELETE FROM variant_option_values WHERE option_value_id = :id', ['id' => $valueId]);
        $this->execute('DELETE FROM product_option_values WHERE id = :id', ['id' => $valueId]);
    }

    public function assignValue(int $variantId, int $valueId): void
    {
        $this->execute(
            'INSERT INTO variant_option_values (variant_id, option_value_id) VALUES (:variant_id, :value_id)',
            ['variant_id' => $variantId, 'value_id' => $valueId]
        );
    }

    public function unassignVariant(int $variantId): void
    {
        $this->execute('DELETE FROM variant_option_values WHERE variant_id = :id', ['id' => $variantId]);
    }

    /**
     * Combinação de cada variante não excluída: variant_id => [option_id => value_id].
     *
     * @return array<int, array<int, int>>
     */
    public function combinations(int $productId): array
    {
        $rows = $this->fetchAll(
            'SELECT vov.variant_id, ov.option_id, ov.id AS value_id
               FROM variant_option_values vov
               JOIN product_variants v ON v.id = vov.variant_id AND v.deleted_at IS NULL
               JOIN product_option_values ov ON ov.id = vov.option_value_id
              WHERE v.product_id = :id',
            ['id' => $productId]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['variant_id']][(int) $row['option_id']] = (int) $row['value_id'];
        }

        return $map;
    }
}
