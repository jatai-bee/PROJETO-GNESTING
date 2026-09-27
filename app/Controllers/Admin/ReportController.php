<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Services\AuditService;
use GNesting\Services\ReportService;

/** Relatórios por período (gestão e proprietário), na tela ou em CSV para a planilha. */
final class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $tab = array_key_exists($request->queryString('aba', 20), ReportService::TABS) ? $request->queryString('aba', 20) : 'vendas';
        $period = $this->reports->period($request->queryString('periodo', 20), $request->queryString('de', 10), $request->queryString('ate', 10));
        $data = $this->reports->data($tab, $period);

        if ($request->queryString('formato', 5) === 'csv') {
            $this->audit->record(AuditService::EXPORT, 'report', null, null, ['aba' => $tab, 'periodo' => $period['label']]);

            return $this->csv($this->reports->rows($tab, $data), sprintf('gnesting-%s-%s-a-%s.csv', $tab, $period['start']->format('Y-m-d'), $period['end']->format('Y-m-d')));
        }

        return $this->render('admin/reports/index', [
            'title' => 'Relatórios | Painel',
            'tab' => $tab,
            'period' => $period,
            'data' => $data,
            'query' => [
                'periodo' => $period['preset'],
                'de' => $period['preset'] === null ? $period['start']->format('Y-m-d') : null,
                'ate' => $period['preset'] === null ? $period['end']->format('Y-m-d') : null,
            ],
        ], 'admin');
    }

    /** @param list<list<string|int>> $rows */
    private function csv(array $rows, string $filename): Response
    {
        $cell = static function (string|int $value): string {
            $value = (string) $value;
            // Texto que começa com = + - @ vira fórmula no Excel: prefixa com apóstrofo
            if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) && !preg_match('/^-?\d+(,\d+)?$/', $value)) {
                $value = "'" . $value;
            }

            return '"' . str_replace('"', '""', $value) . '"';
        };
        $body = "\u{FEFF}" . implode("\r\n", array_map(static fn (array $row): string => implode(';', array_map($cell, $row)), $rows)) . "\r\n";

        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
