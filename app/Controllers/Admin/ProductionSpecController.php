<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Session;
use GNesting\Core\ValidationException;
use GNesting\Core\Validator;
use GNesting\Enums\ProductionStage;
use GNesting\Repositories\MaterialRepository;
use GNesting\Repositories\ProductionSpecRepository;
use GNesting\Repositories\ProductRepository;
use GNesting\Repositories\ProductVariantRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\ProductionFileService;
use GNesting\Services\ProductionSpecService;

/**
 * Fichas de produção (gestor e produção): visão geral, ficha por variação,
 * etapas, cópia entre variações, arquivos privados e download autenticado.
 */
final class ProductionSpecController extends Controller
{
    private const LABELS = [
        'material_id' => 'Material', 'thickness_mm' => 'Espessura', 'cut_width_mm' => 'Largura de corte',
        'cut_height_mm' => 'Altura de corte', 'pieces_per_sheet' => 'Peças por chapa',
        'sheet_yield_percent' => 'Aproveitamento', 'cnc_program_ref' => 'Programa CNC',
        'finish_notes' => 'Acabamento', 'internal_notes' => 'Observações internas',
    ];

    /** Linhas em branco oferecidas no formulário para novas etapas. */
    private const BLANK_ROWS = 3;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
        private readonly ProductionSpecRepository $specs,
        private readonly MaterialRepository $materials,
        private readonly ProductionSpecService $service,
        private readonly ProductionFileService $files,
        private readonly Session $session,
    ) {
    }

    public function overview(Request $request): Response
    {
        $q = $request->queryString('q');
        $rows = $this->specs->overview($q);
        foreach ($rows as &$row) {
            $row['missing'] = ProductionSpecService::missing(
                $row['spec_id'] === null ? null : $row,
                (int) $row['step_count'], (int) $row['total_minutes'], (int) $row['cnc_file_count']
            );
        }
        unset($row);

        return $this->render('admin/production/index', [
            'title' => 'Fichas de produção | Painel',
            'rows' => $rows,
            'q' => $q,
            'complete' => count(array_filter($rows, static fn (array $r): bool => $r['missing'] === [])),
        ], 'admin');
    }

    /** Sem variação na URL: abre a padrão. */
    public function show(Request $request): Response
    {
        $product = $this->product($request);
        $variants = $this->variants->listByProduct((int) $product['id']);

        return $this->redirect("/admin/produtos/{$product['id']}/ficha-producao/{$variants[0]['id']}");
    }

    public function edit(Request $request): Response
    {
        $product = $this->product($request);
        $variant = $this->variant($request, $product);
        $spec = $this->specs->findByVariant((int) $variant['id']);
        $steps = $spec === null ? [] : $this->specs->steps((int) $spec['id']);
        $files = $spec === null ? [] : $this->specs->files((int) $spec['id']);
        $minutes = $this->specs->minutes((int) $variant['id']);

        // Após erro de validação, as linhas digitadas voltam ao formulário
        $oldSteps = $this->session->getFlash('old_steps');
        $rows = is_array($oldSteps) ? $oldSteps : array_map(static fn (array $s): array => [
            'position' => (string) $s['sort_order'], 'stage' => (string) $s['stage'], 'description' => (string) ($s['description'] ?? ''),
            'tool' => (string) ($s['tool'] ?? ''), 'operations' => (string) ($s['operations_count'] ?? ''),
            'minutes' => (string) $s['estimated_minutes'], 'passive' => $s['is_passive'] ? '1' : '',
        ], $steps);
        $next = (count($rows) + 1) * 10;
        for ($i = 0; $i < self::BLANK_ROWS; $i++) {
            $rows[] = ['position' => (string) ($next + $i * 10), 'stage' => '', 'description' => '', 'tool' => '', 'operations' => '', 'minutes' => '', 'passive' => ''];
        }

        return $this->render('admin/production/spec', [
            'title' => 'Ficha de produção — ' . $product['name'] . ' | Painel',
            'product' => $product,
            'variant' => $variant,
            'variants' => $this->variants->listByProduct((int) $product['id']),
            'spec' => $spec,
            'rows' => $rows,
            'files' => $files,
            'minutes' => $minutes,
            'missing' => ProductionSpecService::missing(
                $spec, count($steps), $minutes['total'],
                count(array_filter($files, static fn (array $f): bool => $f['file_type'] === 'cnc'))
            ),
            'materialCost' => $spec === null ? null : ProductionSpecService::materialCostPerPiece($spec),
            'materials' => $this->materials->options(),
            'stages' => array_combine(
                array_map(static fn (ProductionStage $s): string => $s->value, ProductionStage::cases()),
                array_map(static fn (ProductionStage $s): string => $s->label(), ProductionStage::cases()),
            ),
            'fileTypes' => ProductionFileService::TYPES,
            'extensions' => ProductionFileService::EXTENSIONS,
            'maxMb' => intdiv((int) config('uploads.max_production_file_bytes'), 1024 * 1024),
        ], 'admin');
    }

    public function update(Request $request): Response
    {
        $product = $this->product($request);
        $variant = $this->variant($request, $product);
        $rows = $this->stepRows($request);
        $back = "/admin/produtos/{$product['id']}/ficha-producao/{$variant['id']}";

        try {
            Validator::validate($this->specFields($request), [
                'material_id' => 'integer',
                'thickness_mm' => 'decimal',
                'cut_width_mm' => 'gte:1|lte:100000',
                'cut_height_mm' => 'gte:1|lte:100000',
                'pieces_per_sheet' => 'gte:1|lte:10000',
                'sheet_yield_percent' => 'decimal',
                'cnc_program_ref' => 'max:100',
                'finish_notes' => 'max:5000',
                'internal_notes' => 'max:5000',
            ], self::LABELS);
            $this->service->save((int) $variant['id'], $this->specInput($request), $rows);
        } catch (ValidationException $e) {
            $this->flash('errors', $e->errors());
            $this->flash('old', $this->specFields($request));
            $this->flash('old_steps', $rows);
            $this->flash('error', 'Confira os campos destacados.');

            return $this->redirect($back);
        }

        $this->flash('success', 'Ficha de produção salva.');

        return $this->redirect($back);
    }

    public function copy(Request $request): Response
    {
        $product = $this->product($request);
        $variant = $this->variant($request, $product);
        $from = $this->variants->find((int) $product['id'], (int) $request->string('from_variant_id'));

        try {
            if ($from === null) {
                throw new BusinessRuleException('Escolha uma variação deste produto para copiar.');
            }
            $this->service->copy((int) $from['id'], (int) $variant['id']);
            $this->flash('success', "Ficha copiada de {$from['sku']}. Revise e envie os arquivos desta variação.");
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect("/admin/produtos/{$product['id']}/ficha-producao/{$variant['id']}");
    }

    public function upload(Request $request): Response
    {
        $product = $this->product($request);
        $variant = $this->variant($request, $product);
        $back = "/admin/produtos/{$product['id']}/ficha-producao/{$variant['id']}";

        $files = $request->files('file');
        try {
            if ($files === []) {
                // post_max_size estourado chega sem arquivo e sem campos
                throw new BusinessRuleException($request->contentLength() > 0
                    ? 'O arquivo excede o tamanho máximo aceito pelo servidor.'
                    : 'Selecione um arquivo.');
            }
            $this->files->upload((int) $variant['id'], $files[0], $request->string('file_type'));
            $this->flash('success', 'Arquivo enviado.');
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($back);
    }

    public function deleteFile(Request $request): Response
    {
        $product = $this->product($request);
        $variant = $this->variant($request, $product);
        try {
            $this->files->delete((int) $product['id'], (int) $request->param('fileId'));
            $this->flash('success', 'Arquivo excluído.');
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect("/admin/produtos/{$product['id']}/ficha-producao/{$variant['id']}");
    }

    public function download(Request $request): Response
    {
        $file = $this->files->locate((int) $request->param('fileId')) ?? throw HttpException::notFound();

        return Response::download($file['path'], $file['name']);
    }

    /**
     * Linhas de etapa do formulário (steps[n][campo]). Só texto; o resto é descartado.
     *
     * @return list<array<string, string>>
     */
    private function stepRows(Request $request): array
    {
        $raw = $request->input('steps');
        if (!is_array($raw)) {
            return [];
        }
        $rows = [];
        foreach (array_slice($raw, 0, 60) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            foreach (['position', 'stage', 'description', 'tool', 'operations', 'minutes', 'passive', 'remove'] as $field) {
                $value = $row[$field] ?? '';
                $clean[$field] = is_scalar($value) ? mb_substr(trim((string) $value), 0, 300) : '';
            }
            $rows[] = $clean;
        }

        return $rows;
    }

    /** @return array<string, string> */
    private function specFields(Request $request): array
    {
        $fields = [];
        foreach (array_keys(self::LABELS) as $field) {
            $fields[$field] = $request->string($field);
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function specInput(Request $request): array
    {
        $int = static fn (string $v): ?int => $v === '' ? null : (int) $v;

        return [
            'material_id' => $int($request->string('material_id')),
            'thickness_mm' => parse_decimal($request->string('thickness_mm')),
            'cut_width_mm' => $int($request->string('cut_width_mm')),
            'cut_height_mm' => $int($request->string('cut_height_mm')),
            'pieces_per_sheet' => $int($request->string('pieces_per_sheet')),
            'sheet_yield_percent' => parse_decimal($request->string('sheet_yield_percent')),
            'cnc_program_ref' => $request->string('cnc_program_ref'),
            'finish_notes' => $request->string('finish_notes'),
            'internal_notes' => $request->string('internal_notes'),
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
