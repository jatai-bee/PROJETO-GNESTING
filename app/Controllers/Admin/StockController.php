<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\MaterialRepository;
use GNesting\Repositories\StockRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\StockService;

/**
 * Estoque (gestão e produção): produto acabado de pronta entrega e matéria-prima numa tela só,
 * com o que está em falta primeiro e o acerto de contagem direto na linha.
 */
final class StockController extends Controller
{
    private const FILTERS = ['pronta', 'alerta', 'todos'];

    public function __construct(
        private readonly StockRepository $stock,
        private readonly MaterialRepository $materials,
        private readonly StockService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $tab = $request->queryString('aba', 20) === 'materia-prima' ? 'materia-prima' : 'produtos';
        $filter = in_array($request->queryString('filtro', 20), self::FILTERS, true) ? $request->queryString('filtro', 20) : 'pronta';

        return $this->render('admin/stock/index', [
            'title' => 'Estoque | Painel',
            'tab' => $tab,
            'filter' => $filter,
            'summary' => $this->stock->summary(),
            'goods' => $tab === 'produtos' ? $this->stock->finishedGoods($filter) : [],
            'materials' => $tab === 'materia-prima' ? $this->materials->allWithUsage() : [],
        ], 'admin');
    }

    public function adjust(Request $request): Response
    {
        $variantId = (int) $request->param('variantId');
        $back = $this->safeRedirectPath($request->string('voltar'), '/admin/estoque');
        $quantity = $request->string('quantidade');
        $minimum = $request->string('minimo');
        if (!ctype_digit($quantity) || ($minimum !== '' && !ctype_digit($minimum))) {
            $this->flash('error', 'Use números inteiros para a quantidade e o mínimo.');

            return $this->redirect($back);
        }

        try {
            $this->service->adjust($variantId, (int) $quantity, $minimum === '' ? null : (int) $minimum, $request->string('motivo'));
            $this->flash('success', 'Estoque atualizado.');
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($back);
    }
}
