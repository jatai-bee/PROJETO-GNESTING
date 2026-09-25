<?php

declare(strict_types=1);

namespace GNesting\Services\Production;

use GNesting\Repositories\ProductionJobRepository;

/**
 * Carga e previsão da fila (simples e honesta para uma oficina pequena):
 * - minutos restantes de cada job = minutos de operador das etapas que faltam (ficha atual × quantidade);
 *   sem ficha, uma fração do tempo estimado do pedido;
 * - os jobs são atendidos por prazo prometido (mais urgente primeiro) com a capacidade diária
 *   configurada; a data em que a carga acumulada "termina" é a previsão;
 * - previsão depois do prazo = risco de atraso.
 * Não considera feriados, máquinas separadas nem várias pessoas por etapa.
 */
final class ProductionPlanner
{
    public function __construct(
        private readonly ProductionJobRepository $jobs,
        private readonly int $dailyCapacityMinutes,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $jobs jobs em aberto (ProductionJobRepository::open)
     * @return array{jobs: list<array<string, mixed>>, backlog_minutes: int, backlog_days: float, late: int, at_risk: int}
     */
    public function plan(array $jobs, string $nowUtc, string $todayLocal): array
    {
        $minutesCache = [];
        foreach ($jobs as &$job) {
            $route = array_values(array_filter(explode(',', (string) $job['route'])));
            $job['route_list'] = $route;
            $job['deadline'] = $job['paid_at'] === null ? null : business_days_after((string) $job['paid_at'], (int) $job['production_days']);
            $job['remaining_minutes'] = $this->remaining($job, $route, $minutesCache);
        }
        unset($job);

        // Mais urgente primeiro; empate: pago antes
        usort($jobs, static fn (array $a, array $b): int => [$a['deadline'] ?? '9999', $a['paid_at']] <=> [$b['deadline'] ?? '9999', $b['paid_at']]);

        $capacity = max(1, $this->dailyCapacityMinutes);
        $cumulative = 0;
        $late = 0;
        $atRisk = 0;
        foreach ($jobs as &$job) {
            $cumulative += $job['remaining_minutes'];
            $days = (int) ceil($cumulative / $capacity) - 1; // cabe hoje = 0 dias úteis à frente
            $job['forecast'] = business_days_after($nowUtc, max(0, $days));
            $job['late'] = $job['deadline'] !== null && $job['deadline'] < $todayLocal;
            $job['at_risk'] = !$job['late'] && $job['deadline'] !== null && $job['forecast'] > $job['deadline'];
            $late += (int) $job['late'];
            $atRisk += (int) $job['at_risk'];
        }
        unset($job);

        return [
            'jobs' => $jobs,
            'backlog_minutes' => $cumulative,
            'backlog_days' => round($cumulative / $capacity, 1),
            'late' => $late,
            'at_risk' => $atRisk,
        ];
    }

    /**
     * @param array<string, mixed>              $job
     * @param list<string>                      $route
     * @param array<int, array<string, int>>    $cache minutos por etapa, por variante
     */
    private function remaining(array $job, array $route, array &$cache): int
    {
        $stage = (string) $job['stage'];
        $position = $stage === ProductionFlow::QUEUED ? 0 : (int) array_search($stage, $route, true);
        $left = array_slice($route, $position);

        $byStage = $cache[(int) $job['variant_id']] ??= $this->jobs->operatorMinutesByStage((int) $job['variant_id']);
        if ($byStage !== []) {
            return array_sum(array_map(static fn (string $s): int => $byStage[$s] ?? 0, $left)) * (int) $job['quantity'];
        }
        if ($job['estimated_minutes'] === null || $route === []) {
            return 0;
        }

        return (int) round((int) $job['estimated_minutes'] * count($left) / count($route));
    }
}
