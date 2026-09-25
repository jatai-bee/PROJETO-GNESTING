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

    /**
     * Movimenta o saldo (entrada +, consumo −) e registra no razão. O saldo nunca fica
     * negativo (para em zero); o razão guarda o consumo real, para conferência.
     */
    public function move(int $materialId, string $quantity, string $reason, ?string $refType, ?int $refId, ?int $userId): void
    {
        $this->execute(
            'UPDATE materials SET stock_qty = GREATEST(stock_qty + :qty, 0) WHERE id = :id',
            ['qty' => $quantity, 'id' => $materialId]
        );
        $this->execute(
            'INSERT INTO material_movements (material_id, quantity, reason, reference_type, reference_id, user_id)
             VALUES (:material, :qty, :reason, :ref_type, :ref_id, :user)',
            ['material' => $materialId, 'qty' => $quantity, 'reason' => mb_substr($reason, 0, 200),
                'ref_type' => $refType, 'ref_id' => $refId, 'user' => $userId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function movements(int $materialId, int $limit = 30): array
    {
        return $this->fetchAll(
            'SELECT m.quantity, m.reason, m.reference_type, m.reference_id, m.created_at, a.name AS user_name
               FROM material_movements m LEFT JOIN admins a ON a.user_id = m.user_id
              WHERE m.material_id = :id ORDER BY m.id DESC LIMIT :limit',
            ['id' => $materialId, 'limit' => $limit]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM materials WHERE id = :id', ['id' => $id]);
    }
}
