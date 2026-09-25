<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\AuditContext;
use GNesting\Core\Database;
use GNesting\Core\ValidationException;
use GNesting\Enums\ProductionStage;
use GNesting\Repositories\MaterialRepository;
use GNesting\Repositories\ProductionSpecRepository;

/**
 * Ficha de produção de uma variante: material, corte, aproveitamento, programa CNC,
 * observações e etapas com tempo estimado. Documento interno — nunca exibido na loja.
 *
 * - ficha e etapas são salvas juntas (transação): a ficha nunca fica pela metade;
 * - linhas de etapa totalmente vazias são ignoradas (o formulário traz linhas em branco);
 * - material inativo não pode ser escolhido, mas continua na ficha que já o usa;
 * - espessura vazia assume a do material;
 * - toda alteração é auditada (docs/05 §12).
 */
final class ProductionSpecService
{
    public const MAX_STEPS = 30;

    public function __construct(
        private readonly Database $db,
        private readonly ProductionSpecRepository $specs,
        private readonly MaterialRepository $materials,
        private readonly AuditService $audit,
        private readonly AuditContext $auditContext,
    ) {
    }

    /**
     * @param array<string, mixed>       $input ver ProductionSpecController::specInput()
     * @param list<array<string, string>> $rows  etapas como vieram do formulário
     * @throws ValidationException
     */
    public function save(int $variantId, array $input, array $rows): void
    {
        $this->db->transaction(function () use ($variantId, $input, $rows): void {
            $current = $this->specs->findByVariant($variantId);
            $data = $this->prepareSpec($input, $current);
            $steps = $this->prepareSteps($rows);

            if ($current === null) {
                $specId = $this->specs->create($variantId, $data);
                $before = null;
            } else {
                $specId = (int) $current['id'];
                $before = $this->snapshot($current, $this->specs->steps($specId));
                $this->specs->update($specId, $data);
            }
            $this->specs->replaceSteps($specId, $steps);

            $after = $this->snapshot($data, $steps);
            if ($before === null) {
                $this->audit->record(AuditService::CREATE, 'production_spec', $specId, null, $after + ['variant_id' => $variantId]);
            } else {
                $this->audit->recordChanges(AuditService::UPDATE, 'production_spec', $specId, $before, $after);
            }
        });
    }

    /**
     * Copia material, medidas, observações e etapas de outra variante do mesmo produto
     * (arquivos não são copiados: cada variante tem os seus).
     *
     * @throws BusinessRuleException
     */
    public function copy(int $fromVariantId, int $toVariantId): void
    {
        if ($fromVariantId === $toVariantId) {
            throw new BusinessRuleException('Escolha outra variação para copiar.');
        }
        $this->db->transaction(function () use ($fromVariantId, $toVariantId): void {
            $source = $this->specs->findByVariant($fromVariantId)
                ?? throw new BusinessRuleException('A variação escolhida ainda não tem ficha.');
            $steps = $this->specs->steps((int) $source['id']);

            $data = array_intersect_key($source, array_flip([
                'material_id', 'thickness_mm', 'cut_width_mm', 'cut_height_mm', 'pieces_per_sheet',
                'sheet_yield_percent', 'cnc_program_ref', 'finish_notes', 'internal_notes',
            ])) + ['updated_by_user_id' => $this->auditContext->userId()];

            $target = $this->specs->findByVariant($toVariantId);
            $specId = $target === null ? $this->specs->create($toVariantId, $data) : (int) $target['id'];
            if ($target !== null) {
                $this->specs->update($specId, $data);
            }
            $this->specs->replaceSteps($specId, $steps);

            $this->audit->record(AuditService::UPDATE, 'production_spec', $specId, null, [
                'copied_from_variant_id' => $fromVariantId, 'variant_id' => $toVariantId,
            ]);
        });
    }

    /**
     * O que falta para a ficha estar completa (vazio = completa).
     *
     * @param array<string, mixed>|null $spec
     * @return list<string>
     */
    public static function missing(?array $spec, int $stepCount, int $totalMinutes, int $cncFileCount): array
    {
        if ($spec === null) {
            return ['ficha não iniciada'];
        }
        $missing = [];
        if (empty($spec['material_id']) && empty($spec['material_code'])) {
            $missing[] = 'material';
        }
        if ($stepCount === 0 || $totalMinutes === 0) {
            $missing[] = 'etapas com tempo';
        }
        if (($spec['cnc_program_ref'] ?? '') === '' && $cncFileCount === 0) {
            $missing[] = 'programa CNC (referência ou arquivo)';
        }

        return $missing;
    }

    /**
     * Custo de material por peça: custo da chapa ÷ peças por chapa (em centavos, arredondado).
     *
     * @param array<string, mixed> $spec
     */
    public static function materialCostPerPiece(array $spec): ?int
    {
        if (($spec['material_unit'] ?? null) !== 'sheet' || empty($spec['material_cost_cents']) || empty($spec['pieces_per_sheet'])) {
            return null;
        }

        return intdiv((int) $spec['material_cost_cents'] + intdiv((int) $spec['pieces_per_sheet'], 2), (int) $spec['pieces_per_sheet']);
    }

    /**
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function prepareSpec(array $input, ?array $current): array
    {
        $errors = [];
        $materialId = $input['material_id'];
        $thickness = $input['thickness_mm'];

        if ($materialId !== null) {
            $material = $this->materials->find($materialId);
            $keepsCurrent = $current !== null && (int) $current['material_id'] === $materialId;
            if ($material === null || (!(bool) $material['is_active'] && !$keepsCurrent)) {
                $errors['material_id'] = 'Escolha um material ativo.';
            } else {
                $thickness ??= (string) $material['thickness_mm'];
            }
        }
        if ($input['sheet_yield_percent'] !== null && (float) $input['sheet_yield_percent'] > 100) {
            $errors['sheet_yield_percent'] = 'O aproveitamento vai de 0 a 100%.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $text = static fn (?string $v): ?string => $v === null || $v === '' ? null : $v;

        return [
            'material_id' => $materialId,
            'thickness_mm' => $thickness,
            'cut_width_mm' => $input['cut_width_mm'],
            'cut_height_mm' => $input['cut_height_mm'],
            'pieces_per_sheet' => $input['pieces_per_sheet'],
            'sheet_yield_percent' => $input['sheet_yield_percent'],
            'cnc_program_ref' => $text($input['cnc_program_ref']),
            'finish_notes' => $text($input['finish_notes']),
            'internal_notes' => $text($input['internal_notes']),
            'updated_by_user_id' => $this->auditContext->userId(),
        ];
    }

    /**
     * @param list<array<string, string>> $rows
     * @return list<array<string, mixed>>
     * @throws ValidationException chaves "step_{n}_{campo}"
     */
    private function prepareSteps(array $rows): array
    {
        $steps = [];
        $errors = [];

        foreach ($rows as $n => $row) {
            $row = array_map(static fn ($v): string => trim((string) $v), $row);
            if (($row['remove'] ?? '') !== '') {
                continue;
            }
            if (($row['stage'] ?? '') === '' && ($row['description'] ?? '') === '' && ($row['minutes'] ?? '') === '' && ($row['tool'] ?? '') === '') {
                continue; // linha em branco
            }

            $stage = ProductionStage::tryFrom($row['stage'] ?? '');
            if ($stage === null) {
                $errors["step_{$n}_stage"] = 'Escolha a etapa.';
            }
            $minutes = filter_var($row['minutes'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000]]);
            if ($minutes === false) {
                $errors["step_{$n}_minutes"] = 'Minutos: número inteiro de 0 a 10000.';
            }
            $operations = null;
            if (($row['operations'] ?? '') !== '') {
                $operations = filter_var($row['operations'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]);
                if ($operations === false) {
                    $errors["step_{$n}_operations"] = 'Operações: número de 0 a 1000.';
                }
            }
            if (mb_strlen($row['description'] ?? '') > 200) {
                $errors["step_{$n}_description"] = 'Descrição: até 200 caracteres.';
            }
            if (mb_strlen($row['tool'] ?? '') > 100) {
                $errors["step_{$n}_tool"] = 'Ferramenta: até 100 caracteres.';
            }

            $steps[] = [
                'stage' => $stage?->value,
                'description' => ($row['description'] ?? '') === '' ? null : $row['description'],
                'tool' => ($row['tool'] ?? '') === '' ? null : $row['tool'],
                'operations_count' => $operations === false ? null : $operations,
                'estimated_minutes' => $minutes === false ? 0 : $minutes,
                'is_passive' => ($row['passive'] ?? '') !== '' && $row['passive'] !== '0',
                'position' => (int) ($row['position'] ?? ($n + 1) * 10),
            ];
        }

        if (count($steps) > self::MAX_STEPS) {
            $errors['steps'] = 'Limite de ' . self::MAX_STEPS . ' etapas por ficha.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Ordem: campo "posição" (permite reordenar sem JavaScript); empate mantém a ordem do formulário
        $order = array_keys($steps);
        usort($order, static fn (int $a, int $b): int => [$steps[$a]['position'], $a] <=> [$steps[$b]['position'], $b]);

        return array_map(static fn (int $i): array => $steps[$i], $order);
    }

    /**
     * Estado comparável para a auditoria.
     *
     * @param array<string, mixed>       $spec
     * @param list<array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    private function snapshot(array $spec, array $steps): array
    {
        $fields = array_intersect_key($spec, array_flip([
            'material_id', 'thickness_mm', 'cut_width_mm', 'cut_height_mm', 'pieces_per_sheet',
            'sheet_yield_percent', 'cnc_program_ref', 'finish_notes', 'internal_notes',
        ]));
        foreach (['thickness_mm', 'sheet_yield_percent'] as $decimal) {
            $fields[$decimal] = $fields[$decimal] === null ? null : number_format((float) $fields[$decimal], 2, '.', '');
        }

        return $fields + [
            'steps' => implode('; ', array_map(
                static fn (array $s): string => $s['stage'] . ' ' . (int) $s['estimated_minutes'] . 'min' . ((bool) $s['is_passive'] ? ' (passiva)' : ''),
                $steps
            )),
        ];
    }
}
