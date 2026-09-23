<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

final class CategoryRepository extends Repository
{
    private const FIELDS = 'id, parent_id, name, slug, description, sort_order, is_active, meta_title, meta_description, created_at, updated_at';

    /**
     * Todas as categorias não excluídas, com nome do pai e total de produtos.
     *
     * @return list<array<string, mixed>>
     */
    public function allWithCounts(): array
    {
        return $this->fetchAll(
            'SELECT c.id, c.parent_id, c.name, c.slug, c.sort_order, c.is_active, p.name AS parent_name,
                    (SELECT COUNT(*) FROM products pr WHERE pr.category_id = c.id AND pr.deleted_at IS NULL) AS product_count
               FROM categories c
               LEFT JOIN categories p ON p.id = c.parent_id
              WHERE c.deleted_at IS NULL
              ORDER BY COALESCE(p.sort_order, c.sort_order), COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.sort_order, c.name'
        );
    }

    /** @return list<array{id:int,parent_id:?int,name:string,is_active:int}> */
    public function options(): array
    {
        return $this->fetchAll(
            'SELECT id, parent_id, name, is_active FROM categories WHERE deleted_at IS NULL ORDER BY sort_order, name'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::FIELDS . ' FROM categories WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return $this->fetchValue(
            'SELECT 1 FROM categories WHERE slug = :slug AND id <> :except LIMIT 1',
            ['slug' => $slug, 'except' => $exceptId ?? 0]
        ) !== null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO categories (parent_id, name, slug, description, sort_order, is_active, meta_title, meta_description)
             VALUES (:parent_id, :name, :slug, :description, :sort_order, :is_active, :meta_title, :meta_description)',
            $data
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE categories
                SET parent_id = :parent_id, name = :name, slug = :slug, description = :description,
                    sort_order = :sort_order, is_active = :is_active, meta_title = :meta_title,
                    meta_description = :meta_description
              WHERE id = :id AND deleted_at IS NULL',
            $data + ['id' => $id]
        );
    }

    /** Exclusão lógica. O slug é liberado para reutilização. */
    public function softDelete(int $id): void
    {
        $this->execute(
            "UPDATE categories
                SET deleted_at = UTC_TIMESTAMP(), is_active = 0, slug = LEFT(CONCAT(slug, '-excluida-', id), 120)
              WHERE id = :id AND deleted_at IS NULL",
            ['id' => $id]
        );
    }

    public function countChildren(int $id): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM categories WHERE parent_id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public function countProducts(int $id): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM products WHERE category_id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }
}
