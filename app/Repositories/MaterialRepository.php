<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/** Matéria-prima (chapas, peças compradas). Uso interno: nunca exibida na loja. */
final class MaterialRepository extends Repository
{
    private const FIELDS = 'id, code, name, thickness_mm, sheet_width_mm, sheet_length_mm, unit, cost_cents,
        stock_qty, reorder_level, is_active, created_at, updated_at';

    /** @return list<array<string, mixed>> com o número de fichas que usam cada material */
    public function allWithUsage(): array
    {
        return $this->fetchAll(
            'SELECT ' . self::FIELDS . ',
                    (SELECT COUNT(*) FROM production_specs ps WHERE ps.material_id = materials.id) AS spec_count,
                    (reorder_level IS NOT NULL AND stock_qty <= reorder_level) AS needs_reorder
               FROM materials ORDER BY is_active DESC, name, thickness_mm'
        );
    }

    /** @return list<array<string, mixed>> ativos, para o seletor da ficha */
    public function options(): array
    {
        return $this->fetchAll(
            'SELECT id, code, name, thickness_mm, unit, cost_cents, is_active FROM materials ORDER BY is_active DESC, name, thickness_mm'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT ' . self::FIELDS . ' FROM materials WHERE id = :id', ['id' => $id]);
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->fetchValue(
            'SELECT 1 FROM materials WHERE code = :code AND id <> :except',
            ['code' => $code, 'except' => $exceptId ?? 0]
        ) !== null;
    }

    public function usage(int $id): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM production_specs WHERE material_id = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO materials (code, name, thickness_mm, sheet_width_mm, sheet_length_mm, unit, cost_cents, stock_qty, reorder_level, is_active)
             VALUES (:code, :name, :thickness_mm, :sheet_width_mm, :sheet_length_mm, :unit, :cost_cents, :stock_qty, :reorder_level, :is_active)',
            $data
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE materials
                SET code = :code, name = :name, thickness_mm = :thickness_mm, sheet_width_mm = :sheet_width_mm,
                    sheet_length_mm = :sheet_length_mm, unit = :unit, cost_cents = :cost_cents, stock_qty = :stock_qty,
                    reorder_level = :reorder_level, is_active = :is_active
              WHERE id = :id',
            $data + ['id' => $id]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM materials WHERE id = :id', ['id' => $id]);
    }
}
