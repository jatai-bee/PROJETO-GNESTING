<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Database;
use GNesting\Core\ValidationException;
use GNesting\Repositories\MaterialRepository;

/**
 * Cadastro de matéria-prima.
 * - código único, guardado em maiúsculas (ex.: MDF-AMD-06);
 * - material usado por alguma ficha não pode ser excluído (a ficha perderia o vínculo):
 *   desative-o para tirá-lo das novas fichas;
 * - alterações de custo e de saldo são auditadas.
 */
final class MaterialService
{
    public const UNITS = ['sheet' => 'Chapa', 'm2' => 'm²', 'unit' => 'Unidade'];

    public function __construct(
        private readonly Database $db,
        private readonly MaterialRepository $materials,
        private readonly AuditService $audit,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): int
    {
        return $this->db->transaction(function () use ($input): int {
            $data = $this->prepare($input, null);
            $id = $this->materials->create($data);
            $this->audit->record(AuditService::CREATE, 'material', $id, null, $data);

            return $id;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): void
    {
        $this->db->transaction(function () use ($id, $input): void {
            $current = $this->materials->find($id) ?? throw new BusinessRuleException('Material não encontrado.');
            $data = $this->prepare($input, $id);
            $this->materials->update($id, $data);

            $this->audit->recordChanges(AuditService::PRICE_CHANGE, 'material', $id,
                ['cost_cents' => $current['cost_cents']], ['cost_cents' => $data['cost_cents']]);
            $this->audit->recordChanges(AuditService::STOCK_CHANGE, 'material', $id,
                ['stock_qty' => $current['stock_qty']], ['stock_qty' => $data['stock_qty']]);
            $this->audit->recordChanges(AuditService::UPDATE, 'material', $id,
                $current, array_diff_key($data, ['cost_cents' => true, 'stock_qty' => true]));
        });
    }

    /** @throws BusinessRuleException */
    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $current = $this->materials->find($id) ?? throw new BusinessRuleException('Material não encontrado.');
            $usage = $this->materials->usage($id);
            if ($usage > 0) {
                throw new BusinessRuleException(
                    "\"{$current['name']}\" é usado em {$usage} ficha(s) de produção. Desative-o em vez de excluir."
                );
            }
            $this->materials->delete($id);
            $this->audit->record(AuditService::DELETE, 'material', $id, ['code' => $current['code'], 'name' => $current['name']]);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function prepare(array $input, ?int $id): array
    {
        $code = strtoupper((string) $input['code']);
        if ($this->materials->codeExists($code, $id)) {
            throw new ValidationException(['code' => 'Já existe um material com este código.']);
        }
        if (!array_key_exists((string) $input['unit'], self::UNITS)) {
            throw new ValidationException(['unit' => 'Escolha a unidade.']);
        }

        return [
            'code' => $code,
            'name' => (string) $input['name'],
            'thickness_mm' => $input['thickness_mm'],
            'sheet_width_mm' => $input['sheet_width_mm'],
            'sheet_length_mm' => $input['sheet_length_mm'],
            'unit' => (string) $input['unit'],
            'cost_cents' => $input['cost_cents'],
            'stock_qty' => $input['stock_qty'],
            'reorder_level' => $input['reorder_level'],
            'is_active' => (int) (bool) $input['is_active'],
        ];
    }
}
