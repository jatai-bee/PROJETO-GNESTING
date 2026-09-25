<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Database;
use GNesting\Core\ValidationException;
use GNesting\Enums\PersonalizationType;
use GNesting\Repositories\PersonalizationRepository;
use GNesting\Repositories\ProductRepository;

/**
 * Cadastro das regras de personalização (painel).
 *
 * Por tipo:
 * - text: mín./máx. de caracteres (máx. até 100) e preset de caracteres obrigatório;
 * - initial: 1 a 3 letras (preset "letters" fixo);
 * - date: data válida, sem limites de texto;
 * - select: opções "Rótulo | acréscimo", uma por linha (até 30).
 * Opções removidas do texto são DESATIVADAS, não apagadas (carrinhos e pedidos as referenciam).
 * A chave (field_key) é gerada do rótulo na criação e não muda depois.
 */
final class PersonalizationRuleService
{
    public const MAX_RULES = 10;
    public const MAX_VALUES = 30;
    public const MAX_TEXT_LENGTH = 100;

    public function __construct(
        private readonly Database $db,
        private readonly PersonalizationRepository $rules,
        private readonly ProductRepository $products,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input ver PersonalizationController::input()
     * @throws ValidationException|BusinessRuleException
     */
    public function create(int $productId, array $input): int
    {
        return $this->db->transaction(function () use ($productId, $input): int {
            $this->assertProduct($productId);
            if ($this->rules->countRules($productId) >= self::MAX_RULES) {
                throw new BusinessRuleException('Limite de ' . self::MAX_RULES . ' campos de personalização por produto.');
            }
            [$data, $values] = $this->prepare($input);

            $key = str_replace('-', '_', slugify($data['label'])) ?: 'campo';
            $key = substr($key, 0, 45);
            for ($n = 2, $base = $key; $this->rules->keyExists($productId, $key); $n++) {
                $key = "{$base}_{$n}";
            }

            $ruleId = $this->rules->create($productId, $data + ['field_key' => $key]);
            $this->syncValues($ruleId, $values);
            $this->rules->syncProductFlag($productId);
            $this->audit->record(AuditService::CREATE, 'personalization_rule', $ruleId, null, $data + ['product_id' => $productId]);

            return $ruleId;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException|BusinessRuleException
     */
    public function update(int $productId, int $ruleId, array $input): void
    {
        $this->db->transaction(function () use ($productId, $ruleId, $input): void {
            $current = $this->rules->find($productId, $ruleId) ?? throw new BusinessRuleException('Campo não encontrado.');
            [$data, $values] = $this->prepare($input);

            $this->rules->update($ruleId, $data);
            $this->syncValues($ruleId, $values);
            $this->rules->syncProductFlag($productId);

            $this->audit->recordChanges(AuditService::PRICE_CHANGE, 'personalization_rule', $ruleId,
                ['price_delta_cents' => $current['price_delta_cents']], ['price_delta_cents' => $data['price_delta_cents']]);
            $this->audit->recordChanges(AuditService::UPDATE, 'personalization_rule', $ruleId,
                $current, array_diff_key($data, ['price_delta_cents' => true]));
        });
    }

    /** Excluir limpa a regra dos carrinhos (CASCADE); pedidos mantêm o snapshot. */
    public function delete(int $productId, int $ruleId): void
    {
        $this->db->transaction(function () use ($productId, $ruleId): void {
            $current = $this->rules->find($productId, $ruleId) ?? throw new BusinessRuleException('Campo não encontrado.');
            $this->rules->delete($ruleId);
            $this->rules->syncProductFlag($productId);
            $this->audit->record(AuditService::DELETE, 'personalization_rule', $ruleId, [
                'product_id' => $productId, 'label' => $current['label'], 'type' => $current['type'],
            ]);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<array{code: string, label: string, price_delta_cents: int}>}
     * @throws ValidationException
     */
    private function prepare(array $input): array
    {
        $errors = [];
        $type = PersonalizationType::tryFrom((string) $input['type']);
        if ($type === null) {
            throw new ValidationException(['type' => 'Escolha um tipo de campo.']);
        }

        $data = [
            'label' => (string) $input['label'],
            'help_text' => ((string) $input['help_text']) === '' ? null : (string) $input['help_text'],
            'type' => $type->value,
            'is_required' => (int) (bool) $input['is_required'],
            'min_length' => null,
            'max_length' => null,
            'charset' => null,
            'max_size_mm' => $type === PersonalizationType::Select || $type === PersonalizationType::Date ? null : $input['max_size_mm'],
            'price_delta_cents' => (int) $input['price_delta_cents'],
            'sort_order' => (int) $input['sort_order'],
            'is_active' => (int) (bool) $input['is_active'],
        ];
        $values = [];

        switch ($type) {
            case PersonalizationType::Text:
                $min = $input['min_length'] ?? 1;
                $max = $input['max_length'];
                if ($max === null || $max < 1 || $max > self::MAX_TEXT_LENGTH) {
                    $errors['max_length'] = 'Informe o máximo de caracteres (1 a ' . self::MAX_TEXT_LENGTH . ').';
                } elseif ($min < 1 || $min > $max) {
                    $errors['min_length'] = 'O mínimo deve ficar entre 1 e o máximo.';
                }
                if (!array_key_exists((string) $input['charset'], PersonalizationService::CHARSETS)) {
                    $errors['charset'] = 'Escolha quais caracteres são aceitos.';
                }
                $data['min_length'] = $min;
                $data['max_length'] = $max;
                $data['charset'] = (string) $input['charset'];
                break;

            case PersonalizationType::Initial:
                $max = $input['max_length'] ?? 1;
                if ($max < 1 || $max > 3) {
                    $errors['max_length'] = 'Iniciais: de 1 a 3 letras.';
                }
                $data['min_length'] = 1;
                $data['max_length'] = $max;
                $data['charset'] = 'letters';
                break;

            case PersonalizationType::Select:
                try {
                    $values = $this->parseValues((string) $input['values_text']);
                } catch (BusinessRuleException $e) {
                    $errors['values_text'] = $e->getMessage();
                }
                break;

            case PersonalizationType::Date:
                break;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$data, $values];
    }

    /**
     * "Rótulo" ou "Rótulo | 12,50" por linha.
     *
     * @return list<array{code: string, label: string, price_delta_cents: int}>
     * @throws BusinessRuleException
     */
    private function parseValues(string $text): array
    {
        $values = [];
        foreach (preg_split('/\R/', $text) ?: [] as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            [$label, $price] = array_map('trim', array_pad(explode('|', $line, 2), 2, ''));
            $label = (string) preg_replace('/\s+/u', ' ', $label);
            $line = $number + 1;
            if ($label === '' || mb_strlen($label) > 80) {
                throw new BusinessRuleException("Linha {$line}: informe um rótulo de até 80 caracteres.");
            }
            $delta = $price === '' ? 0 : parse_money($price);
            if ($delta === null) {
                throw new BusinessRuleException("Linha {$line}: acréscimo inválido. Use o formato \"Rótulo | 12,50\".");
            }
            $code = substr(slugify($label), 0, 50) ?: 'opcao';
            if (isset($values[$code])) {
                throw new BusinessRuleException("Linha {$line}: \"{$label}\" está repetido.");
            }
            $values[$code] = ['code' => $code, 'label' => $label, 'price_delta_cents' => $delta];
        }
        if ($values === []) {
            throw new BusinessRuleException('Cadastre ao menos uma opção, uma por linha.');
        }
        if (count($values) > self::MAX_VALUES) {
            throw new BusinessRuleException('Limite de ' . self::MAX_VALUES . ' opções.');
        }

        return array_values($values);
    }

    /** @param list<array{code: string, label: string, price_delta_cents: int}> $values */
    private function syncValues(int $ruleId, array $values): void
    {
        $existing = [];
        foreach ($this->rules->values($ruleId) as $row) {
            $existing[(string) $row['code']] = (int) $row['id'];
        }

        foreach ($values as $position => $value) {
            $sort = ($position + 1) * 10;
            if (isset($existing[$value['code']])) {
                $this->rules->updateValue($existing[$value['code']], $value['label'], $value['price_delta_cents'], $sort, true);
                unset($existing[$value['code']]);
            } else {
                $this->rules->createValue($ruleId, $value['code'], $value['label'], $value['price_delta_cents'], $sort);
            }
        }

        // Opções que saíram da lista: desativadas (preserva carrinhos e histórico)
        foreach ($this->rules->values($ruleId) as $row) {
            if (isset($existing[(string) $row['code']]) && (bool) $row['is_active']) {
                $this->rules->updateValue((int) $row['id'], (string) $row['label'], (int) $row['price_delta_cents'], (int) $row['sort_order'], false);
            }
        }
    }

    private function assertProduct(int $productId): void
    {
        if ($this->products->findForAdmin($productId) === null) {
            throw new BusinessRuleException('Produto não encontrado.');
        }
    }
}
