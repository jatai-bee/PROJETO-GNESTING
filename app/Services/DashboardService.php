<?php

declare(strict_types=1);

namespace GNesting\Services;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use GNesting\Repositories\DashboardRepository;

/**
 * Indicadores do painel por período, comparados com o período anterior de mesma duração.
 * Os dias são contados no fuso da loja; o banco guarda UTC.
 */
final class DashboardService
{
    public const PERIODS = ['7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias', '90d' => 'Últimos 90 dias', 'mes' => 'Este mês'];
    public const DEFAULT_PERIOD = '30d';

    public function __construct(private readonly DashboardRepository $repository)
    {
    }

    /**
     * @return array{
     *   period: string, label: string, start: DateTimeImmutable, end: DateTimeImmutable,
     *   totals: array{revenue:int, orders:int, items:int, new_customers:int, average:int},
     *   previous: array{revenue:int, orders:int, items:int, new_customers:int, average:int},
     *   series: list<array{date: string, label: string, revenue: int, orders: int}>,
     *   top: list<array{product_id:int|null, name:string, quantity:int, revenue:int}>,
     *   categories: list<array{name:string, revenue:int}>
     * }
     */
    public function sales(string $period, ?DateTimeImmutable $now = null): array
    {
        $period = isset(self::PERIODS[$period]) ? $period : self::DEFAULT_PERIOD;
        $zone = new DateTimeZone((string) config('app.timezone', 'America/Sao_Paulo'));
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($zone);
        $today = $now->setTime(0, 0);
        $end = $today->modify('+1 day');
        $start = match ($period) {
            '7d' => $today->modify('-6 days'),
            '90d' => $today->modify('-89 days'),
            'mes' => $today->modify('first day of this month'),
            default => $today->modify('-29 days'),
        };
        $length = (int) $start->diff($end)->days;
        $previousStart = $start->modify("-{$length} days");

        $utc = static fn (DateTimeImmutable $d): string => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $withAverage = static fn (array $t): array => $t + ['average' => $t['orders'] > 0 ? intdiv($t['revenue'], $t['orders']) : 0];

        $days = $this->repository->revenueByDay($utc($start), $utc($end), $now->format('P'));
        $series = [];
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $day) {
            $key = $day->format('Y-m-d');
            $series[] = ['date' => $key, 'label' => $day->format('d/m')] + ($days[$key] ?? ['revenue' => 0, 'orders' => 0]);
        }

        return [
            'period' => $period,
            'label' => self::PERIODS[$period],
            'start' => $start,
            'end' => $end->modify('-1 day'),
            'totals' => $withAverage($this->repository->totals($utc($start), $utc($end))),
            'previous' => $withAverage($this->repository->totals($utc($previousStart), $utc($start))),
            'series' => $series,
            'top' => $this->repository->topProducts($utc($start), $utc($end), 5),
            'categories' => $this->repository->revenueByCategory($utc($start), $utc($end)),
        ];
    }

    /**
     * Variação percentual contra o período anterior (null quando não há base para comparar).
     */
    public static function change(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return null;
        }

        return (int) round(($current - $previous) * 100 / $previous);
    }
}
