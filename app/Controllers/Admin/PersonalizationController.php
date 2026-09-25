<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\ValidationException;
use GNesting\Enums\PersonalizationType;
use GNesting\Repositories\PersonalizationRepository;
use GNesting\Repositories\ProductImageRepository;
use GNesting\Repositories\ProductRepository;
use GNesting\Repositories\ProductVariantRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\PersonalizationRuleService;

/** Aba "Personalização" do produto: campos que o cliente pode preencher. */
final class PersonalizationController extends Controller
{
    private const LABELS = [
        'label' => 'Rótulo', 'help_text' => 'Instrução', 'type' => 'Tipo', 'min_length' => 'Mínimo de caracteres',
        'max_length' => 'Máximo', 'charset' => 'Caracteres aceitos', 'max_size_mm' => 'Área máxima de gravação',
        'price_delta' => 'Acréscimo', 'sort_order' => 'Ordem', 'values_text' => 'Opções',
    ];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly PersonalizationRepository $rules,
        private readonly ProductImageRepository $images,
        private readonly ProductVariantRepository $variants,
        private readonly PersonalizationRuleService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $product = $this->product($request);

        return $this->render('admin/products/personalization', [
            'title' => 'Personalização — ' . $product['name'] . ' | Painel',
            'product' => $product,
            'rules' => $this->rules->rulesForProduct((int) $product['id'], false),
        ] + $this->tabCounts($product), 'admin');
    }

    public function create(Request $request): Response
    {
        return $this->form($this->product($request), null);
    }

    public function store(Request $request): Response
    {
        $product = $this->product($request);
        $this->validate($request, $this->rules(), self::LABELS);
        try {
            $this->service->create((int) $product['id'], $this->input($request));
        } catch (BusinessRuleException $e) {
            throw new ValidationException(['label' => $e->getMessage()]);
        }
        $this->flash('success', 'Campo de personalização criado.');

        return $this->redirect("/admin/produtos/{$product['id']}/personalizacao");
    }

    public function edit(Request $request): Response
    {
        $product = $this->product($request);

        return $this->form($product, $this->rule($request, $product));
    }

    public function update(Request $request): Response
    {
        $product = $this->product($request);
        $rule = $this->rule($request, $product);
        $this->validate($request, $this->rules(), self::LABELS);
        $this->service->update((int) $product['id'], (int) $rule['id'], $this->input($request));
        $this->flash('success', 'Campo de personalização atualizado.');

        return $this->redirect("/admin/produtos/{$product['id']}/personalizacao");
    }

    public function destroy(Request $request): Response
    {
        $product = $this->product($request);
        $rule = $this->rule($request, $product);
        $this->service->delete((int) $product['id'], (int) $rule['id']);
        $this->flash('success', "Campo \"{$rule['label']}\" excluído. Pedidos já feitos mantêm o que foi escolhido.");

        return $this->redirect("/admin/produtos/{$product['id']}/personalizacao");
    }

    /**
     * @param array<string, mixed>      $product
     * @param array<string, mixed>|null $rule
     */
    private function form(array $product, ?array $rule): Response
    {
        return $this->render('admin/products/personalization-form', [
            'title' => ($rule ? 'Editar campo' : 'Novo campo') . ' — ' . $product['name'] . ' | Painel',
            'product' => $product,
            'rule' => $rule,
            'types' => array_combine(
                array_map(static fn (PersonalizationType $t): string => $t->value, PersonalizationType::cases()),
                array_map(static fn (PersonalizationType $t): string => $t->label(), PersonalizationType::cases()),
            ),
        ] + $this->tabCounts($product), 'admin');
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        return [
            'label' => 'required|max:80',
            'help_text' => 'max:200',
            'type' => 'required|in:' . implode(',', array_map(static fn ($t) => $t->value, PersonalizationType::cases())),
            'min_length' => 'gte:1|lte:100',
            'max_length' => 'gte:1|lte:100',
            'max_size_mm' => 'gte:1|lte:10000',
            'price_delta' => 'money',
            'sort_order' => 'gte:0|lte:1000',
            'values_text' => 'max:4000',
        ];
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $int = static function (string $value): ?int {
            return $value === '' ? null : (int) $value;
        };
        $delta = $request->string('price_delta');

        return [
            'label' => (string) preg_replace('/\s+/u', ' ', $request->string('label')),
            'help_text' => $request->string('help_text'),
            'type' => $request->string('type'),
            'is_required' => $request->boolean('is_required'),
            'min_length' => $int($request->string('min_length')),
            'max_length' => $int($request->string('max_length')),
            'charset' => $request->string('charset'),
            'max_size_mm' => $int($request->string('max_size_mm')),
            'price_delta_cents' => $delta === '' ? 0 : (int) parse_money($delta),
            'sort_order' => (int) $request->string('sort_order'),
            'is_active' => $request->boolean('is_active'),
            'values_text' => $request->string('values_text'),
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array{imageCount: int, variantCount: int, ruleCount: int}
     */
    private function tabCounts(array $product): array
    {
        return [
            'imageCount' => $this->images->countByProduct((int) $product['id']),
            'variantCount' => $this->variants->countByProduct((int) $product['id']),
            'ruleCount' => $this->rules->countRules((int) $product['id']),
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
    private function rule(Request $request, array $product): array
    {
        return $this->rules->find((int) $product['id'], (int) $request->param('ruleId')) ?? throw HttpException::notFound();
    }
}
