<?php

declare(strict_types=1);

namespace GNesting\Services;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use GNesting\Repositories\DashboardRepository;
use GNesting\Repositories\ReportRepository;

/**
 * Relatórios do painel: período (atalho ou datas), dados de cada aba e a versão em linhas para CSV.
 */
final class ReportService
{
    public const TABS = ['vendas' => 'Vendas', 'produtos' => 'Produtos', 'categorias' => 'Categorias', 'clientes' => 'Clientes', 'producao' => 'Produção'];
    public const PRESETS = ['7d' => '7 dias', '30d' => '30 dias', '90d' => '90 dias', 'mes' => 'Este mês', 'mes-anterior' => 'Mês anterior', 'ano' => 'Este ano'];
    private const MAX_DAYS = 1100;

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly DashboardRepository $dashboard,
    ) {
    }

    /**
     * Período no fuso da loja. Datas inválidas caem no atalho; início depois do fim é invertido.
     *
     * @return array{preset: ?string, start: DateTimeImmutable, end: DateTimeImmutable, days: int, label: string}
     */
    public function period(string $preset, string $from, string $to, ?DateTimeImmutable $now = null): array
    {
        $zone = new DateTimeZone((string) config('app.timezone', 'America/Sao_Paulo'));
        $today = ($now ?? new DateTimeImmutable('now'))->setTimezone($zone)->setTime(0, 0);
        $parse = static function (string $value) use ($zone): ?DateTimeImmutable {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);

            return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
        };

        $start = $parse($from);
        $end = $parse($to);
        if ($start !== null && $end !== null) {
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }
            if ((int) $start->diff($end)->days > self::MAX_DAYS) {
                $start = $end->modify('-' . self::MAX_DAYS . ' days');
            }
            $preset = null;
        } else {
            $preset = isset(self::PRESETS[$preset]) ? $preset : '30d';
            [$start, $end] = match ($preset) {
                '7d' => [$today->modify('-6 days'), $today],
                '90d' => [$today->modify('-89 days'), $today],
                'mes' => [$today->modify('first day of this month'), $today],
                'mes-anterior' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
                'ano' => [$today->setDate((int) $today->format('Y'), 1, 1), $today],
                default => [$today->modify('-29 days'), $today],
            };
        }

        return [
            'preset' => $preset,
            'start' => $start,
            'end' => $end,
            'days' => (int) $start->diff($end)->days + 1,
            'label' => $start->format('d/m/Y') . ' a ' . $end->format('d/m/Y'),
        ];
    }

    /**
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable, days: int} $period
     * @return array<string, mixed>
     */
    public function data(string $tab, array $period): array
    {
        [$start, $end] = $this->utc($period);

        return match ($tab) {
            'produtos' => ['rows' => $this->reports->products($start, $end)],
            'categorias' => ['rows' => $this->reports->categories($start, $end)],
            'clientes' => ['rows' => $this->reports->customers($start, $end, 100)],
            'producao' => $this->production($start, $end),
            default => $this->sales($period, $start, $end),
        };
    }

    /**
     * Linhas para o CSV da aba (primeira linha = cabeçalho). Valores em reais com vírgula, como o Excel brasileiro espera.
     *
     * @param array<string, mixed> $data
     * @return list<list<string|int>>
     */
    public function rows(string $tab, array $data): array
    {
        $brl = static fn (int $cents): string => number_format($cents / 100, 2, ',', '');

        return match ($tab) {
            'produtos' => [['Produto', 'Unidades', 'Faturamento (R$)', 'Custo (R$)', 'Margem (%)'], ...array_map(
                static fn (array $r): array => [$r['name'], $r['quantity'], $brl($r['revenue']), $r['with_cost'] ? $brl($r['cost']) : '',
                    $r['with_cost'] && $r['revenue'] > 0 ? (string) round(($r['revenue'] - $r['cost']) * 100 / $r['revenue']) : ''],
                $data['rows']
            )],
            'categorias' => [['Categoria', 'Unidades', 'Faturamento (R$)'], ...array_map(
                static fn (array $r): array => [$r['name'], $r['quantity'], $brl($r['revenue'])], $data['rows']
            )],
            'clientes' => [['Cliente', 'Cidade', 'Pedidos', 'Total pago (R$)', 'Último pagamento'], ...array_map(
                static fn (array $r): array => [$r['name'], $r['city'], $r['orders'], $brl($r['spent']), substr($r['last_paid_at'], 0, 10)], $data['rows']
            )],
            'producao' => [['Indicador', 'Valor'], ['Peças concluídas', $data['pieces']], ['Ordens concluídas', $data['jobs']],
                ['No prazo (%)', $data['on_time_percent'] ?? ''], ['Dias médios do pagamento ao pronto', $data['avg_days'] ?? ''],
                ['Ordens com retrabalho', $data['reworked']]],
            default => [['Data', 'Pedidos', 'Faturamento (R$)'], ...array_map(
                static fn (array $d): array => [$d['date'], $d['orders'], $brl($d['revenue'])], $data['series']
            )],
        };
    }

    /**
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable, days: int} $period
     * @return array<string, mixed>
     */
    private function sales(array $period, string $start, string $end): array
    {
        $totals = $this->dashboard->totals($start, $end);
        $days = $this->dashboard->revenueByDay($start, $end, $period['start']->format('P'));
        $series = [];
        foreach (new DatePeriod($period['start'], new DateInterval('P1D'), $period['end']->modify('+1 day')) as $day) {
            $key = $day->format('Y-m-d');
            $series[] = ['date' => $key, 'label' => $day->format('d/m')] + ($days[$key] ?? ['revenue' => 0, 'orders' => 0]);
        }
        // Período longo: o gráfico agrupa por mês (a tabela e o CSV continuam por dia)
        $chart = $series;
        if ($period['days'] > 92) {
            $months = [];
            foreach ($series as $d) {
                $m = substr($d['date'], 0, 7);
                $months[$m] ??= ['date' => $m, 'label' => substr($m, 5, 2) . '/' . substr($m, 2, 2), 'revenue' => 0, 'orders' => 0];
                $months[$m]['revenue'] += $d['revenue'];
                $months[$m]['orders'] += $d['orders'];
            }
            $chart = array_values($months);
        }

        return [
            'totals' => $totals + ['average' => $totals['orders'] > 0 ? intdiv($totals['revenue'], $totals['orders']) : 0],
            'extras' => $this->reports->extras($start, $end),
            'series' => $series,
            'chart' => $chart,
            'byMonth' => $period['days'] > 92,
        ];
    }

    /** @return array{pieces:int, jobs:int, on_time_percent:?int, avg_days:?float, reworked:int} */
    private function production(string $start, string $end): array
    {
        $jobs = $this->reports->finishedJobs($start, $end);
        $onTime = 0;
        $withDeadline = 0;
        $days = [];
        foreach ($jobs as $job) {
            if ($job['paid_at'] === null) {
                continue;
            }
            $withDeadline++;
            $finishedLocal = format_datetime($job['finished_at'], 'Y-m-d');
            if ($finishedLocal <= business_days_after($job['paid_at'], $job['production_days'])) {
                $onTime++;
            }
            $days[] = max(0, (strtotime($job['finished_at']) - strtotime($job['paid_at'])) / 86400);
        }

        return [
            'pieces' => array_sum(array_column($jobs, 'quantity')),
            'jobs' => count($jobs),
            'on_time_percent' => $withDeadline > 0 ? (int) round($onTime * 100 / $withDeadline) : null,
            'avg_days' => $days !== [] ? round(array_sum($days) / count($days), 1) : null,
            'reworked' => count(array_filter($jobs, static fn (array $j): bool => $j['rework_count'] > 0)),
        ];
    }

    /**
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $period
     * @return array{0: string, 1: string} início e fim (exclusivo) em UTC
     */
    private function utc(array $period): array
    {
        $utc = new DateTimeZone('UTC');

        return [
            $period['start']->setTimezone($utc)->format('Y-m-d H:i:s'),
            $period['end']->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }
}
