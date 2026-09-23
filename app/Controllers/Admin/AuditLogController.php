<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\Paginator;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\AuditLogRepository;
use GNesting\Services\AuditService;

/** Consulta da trilha de auditoria (somente leitura; somente proprietário). */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 30;

    public function __construct(private readonly AuditLogRepository $logs)
    {
    }

    public function index(Request $request): Response
    {
        $actions = (new \ReflectionClass(AuditService::class))->getConstants();
        $entityTypes = $this->logs->distinctEntityTypes();

        $filters = [
            'action' => in_array($request->queryString('acao'), $actions, true) ? $request->queryString('acao') : '',
            'entity_type' => in_array($request->queryString('tipo'), $entityTypes, true) ? $request->queryString('tipo') : '',
        ];
        $paginator = new Paginator($this->logs->count($filters), $request->queryInt('pagina', 1), self::PER_PAGE);

        return $this->render('admin/audit/index', [
            'title' => 'Auditoria | Painel',
            'logs' => $this->logs->paginate($filters, $paginator->perPage, $paginator->offset()),
            'paginator' => $paginator,
            'filters' => $filters,
            'actions' => array_values($actions),
            'entityTypes' => $entityTypes,
        ], 'admin');
    }
}
