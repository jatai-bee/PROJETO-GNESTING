<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\CategoryRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\CategoryService;

final class CategoryController extends Controller
{
    /** Também usadas na importação de configuração (YAML). */
    public const RULES = [
        'name' => 'required|max:100',
        'slug' => 'max:120|slug',
        'parent_id' => 'integer',
        'description' => 'max:2000',
        'sort_order' => 'gte:0|lte:9999',
        'meta_title' => 'max:70',
        'meta_description' => 'max:160',
    ];

    public const LABELS = [
        'name' => 'Nome', 'slug' => 'Endereço (slug)', 'parent_id' => 'Categoria principal',
        'description' => 'Descrição', 'sort_order' => 'Ordem', 'meta_title' => 'Título para buscadores',
        'meta_description' => 'Descrição para buscadores',
    ];

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly CategoryService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render('admin/categories/index', [
            'title' => 'Categorias | Painel',
            'categories' => $this->categories->allWithCounts(),
        ], 'admin');
    }

    public function create(Request $request): Response
    {
        // "+ Subcategoria" na árvore já chega com a mãe escolhida
        $parent = $request->queryInt('mae');

        return $this->form(null, $parent > 0 ? $parent : null);
    }

    public function store(Request $request): Response
    {
        $this->validate($request, self::RULES, self::LABELS);
        $this->service->create($this->input($request));
        $this->flash('success', 'Categoria criada.');

        return $this->redirect('/admin/categorias');
    }

    public function edit(Request $request): Response
    {
        return $this->form($this->findOrFail($request));
    }

    public function update(Request $request): Response
    {
        $category = $this->findOrFail($request);
        $this->validate($request, self::RULES, self::LABELS);
        $this->service->update((int) $category['id'], $this->input($request));
        $this->flash('success', 'Categoria atualizada.');

        return $this->redirect('/admin/categorias');
    }

    public function destroy(Request $request): Response
    {
        $category = $this->findOrFail($request);
        try {
            $this->service->delete((int) $category['id']);
            $this->flash('success', "Categoria \"{$category['name']}\" excluída.");
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/categorias');
    }

    /** @param array<string, mixed>|null $category */
    private function form(?array $category, ?int $parentId = null): Response
    {
        $parents = array_filter(
            $this->categories->options(),
            static fn (array $c) => $c['parent_id'] === null && $c['id'] !== ($category['id'] ?? null)
        );

        return $this->render('admin/categories/form', [
            'title' => ($category ? 'Editar categoria' : 'Nova categoria') . ' | Painel',
            'category' => $category,
            'parents' => $parents,
            'parentId' => $parentId,
        ], 'admin');
    }

    /** @return array<string, mixed> */
    private function findOrFail(Request $request): array
    {
        return $this->categories->find((int) $request->param('id')) ?? throw HttpException::notFound();
    }

    /** @return array{name:string, slug:string, parent_id:?int, description:?string, sort_order:int, is_active:bool, meta_title:?string, meta_description:?string} */
    private function input(Request $request): array
    {
        $parent = $request->string('parent_id');

        return [
            'name' => $request->string('name'),
            'slug' => $request->string('slug'),
            'parent_id' => $parent === '' ? null : (int) $parent,
            'description' => $request->string('description'),
            'sort_order' => (int) $request->string('sort_order'),
            'is_active' => $request->boolean('is_active'),
            'meta_title' => $request->string('meta_title'),
            'meta_description' => $request->string('meta_description'),
        ];
    }
}
