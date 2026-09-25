<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use DateTimeImmutable;
use DateTimeZone;
use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\AuditLogRepository;
use GNesting\Repositories\DashboardRepository;
use GNesting\Repositories\OrderRepository;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardRepository $dashboard,
        private readonly AuditLogRepository $auditLogs,
        private readonly OrderRepository $orders,
    ) {
    }

    public function index(Request $request): Response
    {
        // "Hoje" e "este mês" no fuso da loja, convertidos para UTC (datas do banco)
        $zone = new DateTimeZone((string) config('app.timezone', 'America/Sao_Paulo'));
        $today = new DateTimeImmutable('today', $zone);
        $utc = new DateTimeZone('UTC');

        return $this->render('admin/dashboard', [
            'title' => 'Painel | G-Nesting',
            'counters' => $this->dashboard->counters(),
            'kpis' => $this->orders->kpis(
                $today->setTimezone($utc)->format('Y-m-d H:i:s'),
                $today->modify('first day of this month')->setTimezone($utc)->format('Y-m-d H:i:s'),
            ),
            'recentActivity' => $this->auditLogs->latest(8),
        ], 'admin');
    }
}
