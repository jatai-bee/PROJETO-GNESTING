<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\AuditLogRepository;
use GNesting\Repositories\DashboardRepository;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardRepository $dashboard,
        private readonly AuditLogRepository $auditLogs,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render('admin/dashboard', [
            'title' => 'Painel | G-Nesting',
            'counters' => $this->dashboard->counters(),
            'recentActivity' => $this->auditLogs->latest(8),
        ], 'admin');
    }
}
