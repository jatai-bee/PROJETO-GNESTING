<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\MaterialRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\MaterialService;

/** Matéria-prima (gestor e produção). */
final class MaterialController extends Controller
{
    private const LABELS = [
        'code' => 'Código', 'name' => 'Nome', 'thickness_mm' => 'Espessura', 'sheet_width_mm' => 'Largura da chapa',
        'sheet_length_mm' => 'Comprimento da chapa', 'unit' => 'Unidade', 'cost' => 'Custo', 'stock_qty' => 'Saldo',
        'reorder_level' => 'Estoque mínimo',
    ];

    public function __construct(
        private readonly MaterialRepository $materials,
        private readonly MaterialService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render('admin/materials/index', [
            'title' => 'Materiais | Painel',
            'materials' => $this->materials->allWithUsage(),
        ], 'admin');
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $this->validate($request, $this->rules(), self::LABELS);
        $this->service->create($this->input($request));
        $this->flash('success', 'Material cadastrado.');

        return $this->redirect('/admin/materiais');
    }

    public function edit(Request $request): Response
    {
        return $this->form($this->findOrFail($request));
    }

    public function update(Request $request): Response
    {
        $material = $this->findOrFail($request);
        $this->validate($request, $this->rules(), self::LABELS);
        $this->service->update((int) $material['id'], $this->input($request));
        $this->flash('success', 'Material atualizado.');

        return $this->redirect('/admin/materiais');
    }

    public function destroy(Request $request): Response
    {
        $material = $this->findOrFail($request);
        try {
            $this->service->delete((int) $material['id']);
            $this->flash('success', "Material \"{$material['name']}\" excluído.");
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/materiais');
    }

    /** @param array<string, mixed>|null $material */
    private function form(?array $material): Response
    {
        return $this->render('admin/materials/form', [
            'title' => ($material ? 'Editar material' : 'Novo material') . ' | Painel',
            'material' => $material,
            'units' => MaterialService::UNITS,
        ], 'admin');
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        return [
            'code' => 'required|max:40|sku',
            'name' => 'required|max:100',
            'thickness_mm' => 'required|decimal',
            'sheet_width_mm' => 'gte:1|lte:100000',
            'sheet_length_mm' => 'gte:1|lte:100000',
            'unit' => 'required|in:' . implode(',', array_keys(MaterialService::UNITS)),
            'cost' => 'money',
            'stock_qty' => 'decimal',
            'reorder_level' => 'decimal',
        ];
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $int = static fn (string $v): ?int => $v === '' ? null : (int) $v;
        $cost = $request->string('cost');

        return [
            'code' => $request->string('code'),
            'name' => $request->string('name'),
            'thickness_mm' => parse_decimal($request->string('thickness_mm')),
            'sheet_width_mm' => $int($request->string('sheet_width_mm')),
            'sheet_length_mm' => $int($request->string('sheet_length_mm')),
            'unit' => $request->string('unit'),
            'cost_cents' => $cost === '' ? null : parse_money($cost),
            'stock_qty' => parse_decimal($request->string('stock_qty')) ?? '0.00',
            'reorder_level' => parse_decimal($request->string('reorder_level')),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** @return array<string, mixed> */
    private function findOrFail(Request $request): array
    {
        return $this->materials->find((int) $request->param('id')) ?? throw HttpException::notFound();
    }
}
