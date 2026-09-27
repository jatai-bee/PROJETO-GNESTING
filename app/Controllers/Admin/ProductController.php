<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Paginator;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\ValidationException;
use GNesting\Repositories\CategoryRepository;
use GNesting\Repositories\PersonalizationRepository;
use GNesting\Repositories\ProductImageRepository;
use GNesting\Repositories\ProductRepository;
use GNesting\Repositories\ProductVariantRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\ProductDuplicator;
use GNesting\Services\ProductService;

final class ProductController extends Controller
{
    private const PER_PAGE = 20;

    private const DIMENSIONS = [
        'width_mm', 'height_mm', 'depth_mm', 'weight_g',
        'package_width_mm', 'package_height_mm', 'package_length_mm', 'package_weight_g',
    ];

    private const LABELS = [
        'name' => 'Nome', 'slug' => 'Endereço (slug)', 'category_id' => 'Categoria',
        'short_description' => 'Resumo', 'description' => 'Descrição', 'highlights' => 'Características',
        'production_lead_days' => 'Prazo de produção', 'sku' => 'SKU', 'price' => 'Preço',
        'compare_at_price' => 'Preço "de"', 'material_label' => 'Material', 'finish_label' => 'Acabamento',
        'width_mm' => 'Largura', 'height_mm' => 'Altura', 'depth_mm' => 'Profundidade', 'weight_g' => 'Peso',
        'package_width_mm' => 'Largura da embalagem', 'package_height_mm' => 'Altura da embalagem',
        'package_length_mm' => 'Comprimento da embalagem', 'package_weight_g' => 'Peso com embalagem',
        'stock_mode' => 'Modo de estoque', 'quantity_on_hand' => 'Quantidade em estoque',
        'meta_title' => 'Título para buscadores', 'meta_description' => 'Descrição para buscadores',
        'keywords' => 'Palavras-chave', 'care_instructions' => 'Cuidados', 'assembly_info' => 'Montagem',
        'dispatch_days' => 'Prazo de postagem', 'cost' => 'Custo',
    ];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
        private readonly ProductImageRepository $images,
        private readonly ProductService $service,
        private readonly ProductVariantRepository $variants,
        private readonly PersonalizationRepository $personalization,
        private readonly ProductDuplicator $duplicator,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => $request->queryString('q'),
            'category_id' => $request->queryInt('categoria'),
            'status' => in_array($request->queryString('status'), ['active', 'inactive'], true) ? $request->queryString('status') : '',
        ];
        $paginator = new Paginator($this->products->count($filters), $request->queryInt('pagina', 1), self::PER_PAGE);

        return $this->render('admin/products/index', [
            'title' => 'Produtos | Painel',
            'products' => $this->products->paginate($filters, $paginator->perPage, $paginator->offset()),
            'paginator' => $paginator,
            'filters' => $filters,
            'categories' => $this->categories->options(),
        ], 'admin');
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $this->validate($request, $this->rules(), self::LABELS);
        $id = $this->service->create($this->input($request));
        $this->flash('success', 'Produto criado (inativo). Envie as imagens e depois ative o produto.');

        return $this->redirect("/admin/produtos/{$id}/imagens");
    }

    public function edit(Request $request): Response
    {
        return $this->form($this->findOrFail($request));
    }

    public function update(Request $request): Response
    {
        $product = $this->findOrFail($request);
        $this->validate($request, $this->rules(), self::LABELS);
        $this->service->update((int) $product['id'], $this->input($request));
        $this->flash('success', 'Produto atualizado.');

        return $this->redirect("/admin/produtos/{$product['id']}/editar");
    }

    public function status(Request $request): Response
    {
        $product = $this->findOrFail($request);
        $activate = $request->boolean('active');

        try {
            $this->service->setActive((int) $product['id'], $activate);
            $this->flash('success', $activate ? 'Produto ativado: já pode aparecer na loja.' : 'Produto desativado: não aparece mais na loja.');
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($this->safeRedirectPath($request->string('back'), "/admin/produtos/{$product['id']}/editar"));
    }

    /** Cópia inativa, sem fotos, para cadastrar um produto parecido sem começar do zero. */
    public function duplicate(Request $request): Response
    {
        $product = $this->findOrFail($request);
        $id = $this->duplicator->duplicate((int) $product['id']);
        $this->flash('success', "Cópia de \"{$product['name']}\" criada (inativa). Revise nome, SKU e envie as fotos antes de ativar.");

        return $this->redirect("/admin/produtos/{$id}/editar");
    }

    public function destroy(Request $request): Response
    {
        $product = $this->findOrFail($request);
        $this->service->delete((int) $product['id']);
        $this->flash('success', "Produto \"{$product['name']}\" excluído.");

        return $this->redirect('/admin/produtos');
    }

    /** @param array<string, mixed>|null $product */
    private function form(?array $product): Response
    {
        $id = $product !== null ? (int) $product['id'] : null;
        $images = $id !== null ? $this->images->listByProduct($id) : [];

        return $this->render('admin/products/form', [
            'title' => ($product ? $product['name'] : 'Novo produto') . ' | Painel',
            'product' => $product,
            'categories' => $this->categories->options(),
            'cover' => $images[0]['path'] ?? null,
            'imageCount' => count($images),
            'variantCount' => $id !== null ? $this->variants->countByProduct($id) : 0,
            'ruleCount' => $id !== null ? $this->personalization->countRules($id) : 0,
        ], 'admin');
    }

    /** @return array<string, mixed> */
    private function findOrFail(Request $request): array
    {
        return $this->products->findForAdmin((int) $request->param('id')) ?? throw HttpException::notFound();
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        $rules = [
            'name' => 'required|max:150',
            'slug' => 'max:170|slug',
            'category_id' => 'required|integer',
            'short_description' => 'max:300',
            'description' => 'max:10000',
            'highlights' => 'max:2000',
            'production_lead_days' => 'required|gte:0|lte:120',
            'sku' => 'required|max:40|sku',
            'price' => 'required|money',
            'compare_at_price' => 'money',
            'material_label' => 'max:100',
            'finish_label' => 'max:60',
            'stock_mode' => 'required|in:made_to_order,stock',
            'quantity_on_hand' => 'gte:0|lte:1000000',
            'meta_title' => 'max:70',
            'meta_description' => 'max:160',
            'keywords' => 'max:255',
            'care_instructions' => 'max:2000',
            'assembly_info' => 'max:2000',
            'dispatch_days' => 'gte:0|lte:30',
            'cost' => 'money',
        ];
        foreach (self::DIMENSIONS as $field) {
            $rules[$field] = 'gte:0|lte:100000';
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $price = parse_money($request->string('price'));
        if ($price === null || $price <= 0) {
            throw new ValidationException(['price' => 'Informe um preço maior que zero.']);
        }
        $compare = $request->string('compare_at_price');

        $input = [
            'category_id' => (int) $request->string('category_id'),
            'name' => $request->string('name'),
            'slug' => $request->string('slug'),
            'short_description' => $request->string('short_description'),
            'description' => $request->string('description'),
            'highlights' => $request->string('highlights'),
            'production_lead_days' => (int) $request->string('production_lead_days'),
            'is_featured' => $request->boolean('is_featured'),
            'is_new' => $request->boolean('is_new'),
            'meta_title' => $request->string('meta_title'),
            'meta_description' => $request->string('meta_description'),
            'keywords' => $request->string('keywords'),
            'care_instructions' => $request->string('care_instructions'),
            'assembly_info' => $request->string('assembly_info'),
            'dispatch_days' => $request->string('dispatch_days') === '' ? 1 : (int) $request->string('dispatch_days'),
            'cost_cents' => $request->string('cost') === '' ? null : parse_money($request->string('cost')),
            'sku' => $request->string('sku'),
            'price_cents' => $price,
            'compare_at_price_cents' => $compare === '' ? null : parse_money($compare),
            'material_label' => $request->string('material_label'),
            'finish_label' => $request->string('finish_label'),
            'stock_mode' => $request->string('stock_mode'),
            'quantity_on_hand' => (int) $request->string('quantity_on_hand'),
        ];
        foreach (self::DIMENSIONS as $field) {
            $value = $request->string($field);
            $input[$field] = $value === '' ? null : (int) $value;
        }

        return $input;
    }
}
