<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\ValidationException;
use GNesting\Repositories\PersonalizationRepository;
use GNesting\Repositories\ProductImageRepository;
use GNesting\Repositories\ProductOptionRepository;
use GNesting\Repositories\ProductRepository;
use GNesting\Repositories\ProductVariantRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\VariantService;

/**
 * Aba "Variações" do produto: opções, valores e variantes (SKUs).
 * Ações da página principal recusadas por regra de negócio voltam com mensagem (flash);
 * o formulário de edição da variante usa a convenção GET/POST na mesma URL.
 */
final class VariantController extends Controller
{
    private const DIMENSIONS = [
        'width_mm', 'height_mm', 'depth_mm', 'weight_g',
        'package_width_mm', 'package_height_mm', 'package_length_mm', 'package_weight_g',
    ];

    private const LABELS = [
        'sku' => 'SKU', 'price' => 'Preço', 'compare_at_price' => 'Preço "de"', 'cost' => 'Custo', 'material_label' => 'Material',
        'finish_label' => 'Acabamento', 'stock_mode' => 'Modo de estoque', 'quantity_on_hand' => 'Quantidade em estoque',
        'width_mm' => 'Largura', 'height_mm' => 'Altura', 'depth_mm' => 'Profundidade', 'weight_g' => 'Peso',
        'package_width_mm' => 'Largura da embalagem', 'package_height_mm' => 'Altura da embalagem',
        'package_length_mm' => 'Comprimento da embalagem', 'package_weight_g' => 'Peso com embalagem',
    ];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
        private readonly ProductOptionRepository $options,
        private readonly ProductImageRepository $images,
        private readonly PersonalizationRepository $personalization,
        private readonly VariantService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $product = $this->product($request);

        return $this->render('admin/products/variants', [
            'title' => 'Variações — ' . $product['name'] . ' | Painel',
            'product' => $product,
            'options' => $this->options->optionsWithValues((int) $product['id']),
            'variants' => $this->variants->listByProduct((int) $product['id']),
        ] + $this->tabCounts($product), 'admin');
    }

    public function addOption(Request $request): Response
    {
        $product = $this->product($request);

        return $this->attempt($product, fn () => $this->service->addOption(
            (int) $product['id'], $request->string('name'), $request->string('values')
        ), 'Opção criada. Use "Gerar variações" para criar as combinações.');
    }

    public function addValue(Request $request): Response
    {
        $product = $this->product($request);

        return $this->attempt($product, fn () => $this->service->addValue(
            (int) $product['id'], (int) $request->param('optionId'), $request->string('value')
        ), 'Valor adicionado.');
    }

    public function deleteValue(Request $request): Response
    {
        $product = $this->product($request);

        return $this->attempt($product, fn () => $this->service->deleteValue(
            (int) $product['id'], (int) $request->param('optionId'), (int) $request->param('valueId')
        ), 'Valor excluído.');
    }

    public function deleteOption(Request $request): Response
    {
        $product = $this->product($request);

        return $this->attempt($product, fn () => $this->service->deleteOption(
            (int) $product['id'], (int) $request->param('optionId')
        ), 'Opção excluída.');
    }

    public function generate(Request $request): Response
    {
        $product = $this->product($request);
        try {
            $created = $this->service->generateCombinations((int) $product['id']);
            $this->flash('success', $created === 0
                ? 'Todas as combinações já existem.'
                : "{$created} variação(ões) criada(s) com preço e medidas da variação padrão. Revise SKU, preço e estoque de cada uma.");
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect("/admin/produtos/{$product['id']}/variantes");
    }

    public function edit(Request $request): Response
    {
        $product = $this->product($request);
        $variant = $this->variant($request, $product);

        return $this->render('admin/products/variant-form', [
            'title' => 'Editar variação — ' . $product['name'] . ' | Painel',
            'product' => $product,
            'variant' => $variant,
        ] + $this->tabCounts($product), 'admin');
    }

    public function update(Request $request): Response
    {
        $product = $this->product($request);
        $variant = $this->variant($request, $product);

        $rules = [
            'sku' => 'required|max:40|sku',
            'price' => 'required|money',
            'compare_at_price' => 'money',
            'cost' => 'money',
            'material_label' => 'max:100',
            'finish_label' => 'max:60',
            'stock_mode' => 'required|in:made_to_order,stock',
            'quantity_on_hand' => 'gte:0|lte:1000000',
        ];
        foreach (self::DIMENSIONS as $field) {
            $rules[$field] = 'gte:0|lte:100000';
        }
        $this->validate($request, $rules, self::LABELS);

        $price = parse_money($request->string('price'));
        if ($price === null || $price <= 0) {
            throw new ValidationException(['price' => 'Informe um preço maior que zero.']);
        }
        $compare = $request->string('compare_at_price');
        $input = [
            'sku' => $request->string('sku'),
            'price_cents' => $price,
            'compare_at_price_cents' => $compare === '' ? null : parse_money($compare),
            'cost_cents' => $request->string('cost') === '' ? null : parse_money($request->string('cost')),
            'material_label' => $request->string('material_label'),
            'finish_label' => $request->string('finish_label'),
            'stock_mode' => $request->string('stock_mode'),
            'quantity_on_hand' => (int) $request->string('quantity_on_hand'),
            'is_active' => $request->boolean('is_active'),
        ];
        foreach (self::DIMENSIONS as $field) {
            $value = $request->string($field);
            $input[$field] = $value === '' ? null : (int) $value;
        }

        try {
            $this->service->updateVariant((int) $product['id'], (int) $variant['id'], $input);
        } catch (BusinessRuleException $e) {
            throw new ValidationException(['sku' => $e->getMessage()]);
        }
        $this->flash('success', 'Variação atualizada.');

        return $this->redirect("/admin/produtos/{$product['id']}/variantes");
    }

    public function makeDefault(Request $request): Response
    {
        $product = $this->product($request);

        return $this->attempt($product, fn () => $this->service->setDefault(
            (int) $product['id'], (int) $request->param('variantId')
        ), 'Variação padrão alterada. Ela é a que aparece selecionada na loja e é editada na aba Dados.');
    }

    public function destroy(Request $request): Response
    {
        $product = $this->product($request);

        return $this->attempt($product, fn () => $this->service->deleteVariant(
            (int) $product['id'], (int) $request->param('variantId')
        ), 'Variação excluída. O SKU continua reservado.');
    }

    /** @param array<string, mixed> $product */
    private function attempt(array $product, callable $action, string $success): Response
    {
        try {
            $action();
            $this->flash('success', $success);
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect("/admin/produtos/{$product['id']}/variantes");
    }

    /**
     * Contadores exibidos nas abas do produto.
     *
     * @param array<string, mixed> $product
     * @return array{imageCount: int, variantCount: int, ruleCount: int}
     */
    private function tabCounts(array $product): array
    {
        return [
            'imageCount' => $this->images->countByProduct((int) $product['id']),
            'variantCount' => $this->variants->countByProduct((int) $product['id']),
            'ruleCount' => $this->personalization->countRules((int) $product['id']),
        ];
    }

    /** @return array<string, mixed> */
    private function product(Request $request): array
    {
        return $this->products->findForAdmin((int) $request->param('id')) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function variant(Request $request, array $product): array
    {
        return $this->variants->find((int) $product['id'], (int) $request->param('variantId')) ?? throw HttpException::notFound();
    }
}
