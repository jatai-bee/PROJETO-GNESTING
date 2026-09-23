<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Database;
use GNesting\Core\ValidationException;
use GNesting\Repositories\CategoryRepository;

/**
 * Categorias em até dois níveis (categoria → subcategoria), o suficiente para
 * o catálogo e sem risco de ciclos.
 */
final class CategoryService
{
    public function __construct(
        private readonly Database $db,
        private readonly CategoryRepository $categories,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array{name:string, slug:string, parent_id:?int, description:?string, sort_order:int, is_active:bool, meta_title:?string, meta_description:?string} $input
     */
    public function create(array $input): int
    {
        return $this->db->transaction(function () use ($input): int {
            $data = $this->prepare($input, null);
            $id = $this->categories->create($data);
            $this->audit->record(AuditService::CREATE, 'category', $id, null, $data);

            return $id;
        });
    }

    /** @param array{name:string, slug:string, parent_id:?int, description:?string, sort_order:int, is_active:bool, meta_title:?string, meta_description:?string} $input */
    public function update(int $id, array $input): void
    {
        $this->db->transaction(function () use ($id, $input): void {
            $current = $this->categories->find($id) ?? throw new BusinessRuleException('Categoria não encontrada.');
            if ($input['slug'] === '') {
                $input['slug'] = (string) $current['slug']; // mantém o endereço atual (links e SEO)
            }
            $data = $this->prepare($input, $id);
            $this->categories->update($id, $data);
            $this->audit->recordChanges(AuditService::UPDATE, 'category', $id, $current, $data);
        });
    }

    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $category = $this->categories->find($id) ?? throw new BusinessRuleException('Categoria não encontrada.');

            if ($this->categories->countChildren($id) > 0) {
                throw new BusinessRuleException('Esta categoria tem subcategorias. Mova ou exclua as subcategorias primeiro.');
            }
            $products = $this->categories->countProducts($id);
            if ($products > 0) {
                throw new BusinessRuleException("Esta categoria tem {$products} produto(s). Mova os produtos para outra categoria antes de excluir.");
            }

            $this->categories->softDelete($id);
            $this->audit->record(AuditService::DELETE, 'category', $id, ['name' => $category['name'], 'slug' => $category['slug']]);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function prepare(array $input, ?int $id): array
    {
        $parentId = $input['parent_id'] ?? null;
        if ($parentId !== null) {
            $parent = $this->categories->find($parentId);
            if ($parent === null || $parentId === $id) {
                throw new ValidationException(['parent_id' => 'Categoria principal inválida.']);
            }
            if ($parent['parent_id'] !== null) {
                throw new ValidationException(['parent_id' => 'Escolha uma categoria principal (só são permitidos dois níveis).']);
            }
            if ($id !== null && $this->categories->countChildren($id) > 0) {
                throw new ValidationException(['parent_id' => 'Esta categoria tem subcategorias e não pode ficar dentro de outra.']);
            }
        }

        $slug = $input['slug'];
        if ($slug === '') {
            $slug = SlugGenerator::unique($input['name'], fn (string $s) => $this->categories->slugExists($s, $id), 120);
        } elseif ($this->categories->slugExists($slug, $id)) {
            throw new ValidationException(['slug' => 'Este endereço (slug) já está em uso por outra categoria.']);
        }

        return [
            'parent_id' => $parentId,
            'name' => $input['name'],
            'slug' => $slug,
            'description' => $input['description'] ?: null,
            'sort_order' => $input['sort_order'],
            'is_active' => (int) $input['is_active'],
            'meta_title' => $input['meta_title'] ?: null,
            'meta_description' => $input['meta_description'] ?: null,
        ];
    }
}
