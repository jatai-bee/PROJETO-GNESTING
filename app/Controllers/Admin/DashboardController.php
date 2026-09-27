<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Enums\AdminRole;
use GNesting\Repositories\AuditLogRepository;
use GNesting\Repositories\DashboardRepository;
use GNesting\Repositories\OrderRepository;
use GNesting\Services\DashboardService;

/**
 * Visão geral: vendas do período (gerência), pedidos por etapa, alertas e últimos pedidos.
 * Cada papel vê só o que usa: produção não vê faturamento; atendimento não vê estoque.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardRepository $dashboard,
        private readonly DashboardService $sales,
        private readonly AuditLogRepository $auditLogs,
        private readonly OrderRepository $orders,
    ) {
    }

    public function index(Request $request): Response
    {
        $role = AdminRole::tryFrom((string) ($request->attribute('admin')['role'] ?? ''));
        $canSales = $role?->isAllowed(['manager']) ?? false;
        $canOrders = $role?->isAllowed(['manager', 'production', 'support']) ?? false;
        $canStock = $role?->isAllowed(['manager', 'production']) ?? false;

        return $this->render('admin/dashboard', [
            'title' => 'Visão geral | Painel',
            'canSales' => $canSales,
            'canOrders' => $canOrders,
            'canStock' => $canStock,
            'sales' => $canSales ? $this->sales->sales($request->queryString('periodo', 5)) : null,
            'statusCounts' => $canOrders ? $this->orders->statusCounts() : [],
            'alerts' => $canStock ? $this->dashboard->alerts() : null,
            'recentOrders' => $canOrders ? $this->dashboard->recentOrders(6) : [],
            'counters' => $this->dashboard->counters(),
            'recentActivity' => $role === AdminRole::Owner ? $this->auditLogs->latest(6) : [],
        ], 'admin');
    }
}
