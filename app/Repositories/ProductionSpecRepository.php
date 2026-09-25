<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/**
 * Ficha de produção (1 por variante), etapas com tempos e arquivos privados.
 * Uso interno: nenhuma consulta daqui é usada pela loja.
 */
final class ProductionSpecRepository extends Repository
{
    /**
     * Todas as variantes não excluídas com o resumo da ficha, para a visão geral.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(string $q = ''): array
    {
        $where = 'p.deleted_at IS NULL AND v.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (p.name LIKE :q_name OR v.sku LIKE :q_sku)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params = ['q_name' => $like, 'q_sku' => $like];
        }

        return $this->fetchAll(
            "SELECT p.id AS product_id, p.name AS product_name, p.is_active AS product_active,
                    v.id AS variant_id, v.sku, v.name AS variant_name, v.is_default, v.is_active AS variant_active,
                    ps.id AS spec_id, ps.cnc_program_ref, m.code AS material_code, m.name AS material_name, m.thickness_mm AS material_thickness,
                    (SELECT COUNT(*) FROM production_spec_steps s WHERE s.spec_id = ps.id) AS step_count,
                    (SELECT COALESCE(SUM(s.estimated_minutes), 0) FROM production_spec_steps s WHERE s.spec_id = ps.id) AS total_minutes,
                    (SELECT COUNT(*) FROM production_files f WHERE f.spec_id = ps.id) AS file_count,
                    (SELECT COUNT(*) FROM production_files f WHERE f.spec_id = ps.id AND f.file_type = 'cnc') AS cnc_file_count
               FROM products p
               JOIN product_variants v ON v.product_id = p.id
               LEFT JOIN production_specs ps ON ps.variant_id = v.id
               LEFT JOIN materials m ON m.id = ps.material_id
              WHERE {$where}
              ORDER BY p.name, v.is_default DESC, v.sort_order, v.id",
            $params
        );
    }

    /** @return array<string, mixed>|null ficha da variante, com dados do material */
    public function findByVariant(int $variantId): ?array
    {
        return $this->fetchOne(
            'SELECT ps.id, ps.variant_id, ps.material_id, ps.thickness_mm, ps.cut_width_mm, ps.cut_height_mm, ps.pieces_per_sheet,
                    ps.sheet_yield_percent, ps.cnc_program_ref, ps.finish_notes, ps.internal_notes, ps.updated_at,
                    m.code AS material_code, m.name AS material_name, m.thickness_mm AS material_thickness,
                    m.cost_cents AS material_cost_cents, m.unit AS material_unit,
                    m.sheet_width_mm AS material_sheet_width, m.sheet_length_mm AS material_sheet_length,
                    u.name AS updated_by_name
               FROM production_specs ps
               LEFT JOIN materials m ON m.id = ps.material_id
               LEFT JOIN admins u ON u.user_id = ps.updated_by_user_id
              WHERE ps.variant_id = :id',
            ['id' => $variantId]
        );
    }

    /** @param array<string, mixed> $data */
    public function create(int $variantId, array $data): int
    {
        return $this->insert(
            'INSERT INTO production_specs (variant_id, material_id, thickness_mm, cut_width_mm, cut_height_mm, pieces_per_sheet,
                                           sheet_yield_percent, cnc_program_ref, finish_notes, internal_notes, updated_by_user_id)
             VALUES (:variant_id, :material_id, :thickness_mm, :cut_width_mm, :cut_height_mm, :pieces_per_sheet,
                     :sheet_yield_percent, :cnc_program_ref, :finish_notes, :internal_notes, :updated_by_user_id)',
            $data + ['variant_id' => $variantId]
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $specId, array $data): void
    {
        $this->execute(
            'UPDATE production_specs
                SET material_id = :material_id, thickness_mm = :thickness_mm, cut_width_mm = :cut_width_mm,
                    cut_height_mm = :cut_height_mm, pieces_per_sheet = :pieces_per_sheet,
                    sheet_yield_percent = :sheet_yield_percent, cnc_program_ref = :cnc_program_ref,
                    finish_notes = :finish_notes, internal_notes = :internal_notes, updated_by_user_id = :updated_by_user_id
              WHERE id = :id',
            $data + ['id' => $specId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function steps(int $specId): array
    {
        return $this->fetchAll(
            'SELECT id, stage, description, tool, operations_count, estimated_minutes, is_passive, sort_order
               FROM production_spec_steps WHERE spec_id = :id ORDER BY sort_order, id',
            ['id' => $specId]
        );
    }

    /** @param list<array<string, mixed>> $steps já validadas, na ordem desejada */
    public function replaceSteps(int $specId, array $steps): void
    {
        $this->execute('DELETE FROM production_spec_steps WHERE spec_id = :id', ['id' => $specId]);
        foreach ($steps as $position => $step) {
            $this->execute(
                'INSERT INTO production_spec_steps (spec_id, stage, description, tool, operations_count, estimated_minutes, is_passive, sort_order)
                 VALUES (:spec_id, :stage, :description, :tool, :operations_count, :estimated_minutes, :is_passive, :sort_order)',
                [
                    'spec_id' => $specId,
                    'stage' => $step['stage'],
                    'description' => $step['description'],
                    'tool' => $step['tool'],
                    'operations_count' => $step['operations_count'],
                    'estimated_minutes' => $step['estimated_minutes'],
                    'is_passive' => (int) $step['is_passive'],
                    'sort_order' => ($position + 1) * 10,
                ]
            );
        }
    }

    /**
     * Tempo estimado por unidade (snapshot no pedido, etapa 7; capacidade, etapa 9).
     *
     * @return array{total: int, operator: int, passive: int}
     */
    public function minutes(int $variantId): array
    {
        $row = $this->fetchOne(
            'SELECT COALESCE(SUM(s.estimated_minutes), 0) AS total,
                    COALESCE(SUM(IF(s.is_passive = 0, s.estimated_minutes, 0)), 0) AS operator
               FROM production_specs ps
               JOIN production_spec_steps s ON s.spec_id = ps.id
              WHERE ps.variant_id = :id',
            ['id' => $variantId]
        );
        $total = (int) ($row['total'] ?? 0);
        $operator = (int) ($row['operator'] ?? 0);

        return ['total' => $total, 'operator' => $operator, 'passive' => $total - $operator];
    }

    // ---- Arquivos -------------------------------------------------------------

    /** @return list<array<string, mixed>> mais recentes primeiro */
    public function files(int $specId): array
    {
        return $this->fetchAll(
            'SELECT f.id, f.file_type, f.original_name, f.mime_type, f.size_bytes, f.checksum_sha256, f.version, f.created_at,
                    u.name AS uploaded_by_name
               FROM production_files f
               LEFT JOIN admins u ON u.user_id = f.uploaded_by_user_id
              WHERE f.spec_id = :id
              ORDER BY f.original_name, f.version DESC',
            ['id' => $specId]
        );
    }

    /** @return array<string, mixed>|null arquivo com o produto/variante a que pertence */
    public function findFile(int $fileId): ?array
    {
        return $this->fetchOne(
            'SELECT f.id, f.spec_id, f.file_type, f.original_name, f.stored_path, f.mime_type, f.size_bytes, f.checksum_sha256, f.version,
                    ps.variant_id, v.product_id, v.sku
               FROM production_files f
               JOIN production_specs ps ON ps.id = f.spec_id
               JOIN product_variants v ON v.id = ps.variant_id
              WHERE f.id = :id',
            ['id' => $fileId]
        );
    }

    public function nextVersion(int $specId, string $originalName): int
    {
        return 1 + (int) $this->fetchValue(
            'SELECT COALESCE(MAX(version), 0) FROM production_files WHERE spec_id = :id AND original_name = :name',
            ['id' => $specId, 'name' => $originalName]
        );
    }

    /** @param array<string, mixed> $data */
    public function createFile(int $specId, array $data): int
    {
        return $this->insert(
            'INSERT INTO production_files (spec_id, file_type, original_name, stored_path, mime_type, size_bytes, checksum_sha256, version, uploaded_by_user_id)
             VALUES (:spec_id, :file_type, :original_name, :stored_path, :mime_type, :size_bytes, :checksum_sha256, :version, :uploaded_by_user_id)',
            $data + ['spec_id' => $specId]
        );
    }

    public function deleteFile(int $fileId): void
    {
        $this->execute('DELETE FROM production_files WHERE id = :id', ['id' => $fileId]);
    }
}
