<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Auth;
use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\ProductionJobRepository;
use GNesting\Repositories\ProductionSpecRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\Production\ProductionFlow;
use GNesting\Services\Production\ProductionPlanner;
use GNesting\Services\Production\ProductionService;

/**
 * Fila de produção (gestor e produção). Pensada para uso na oficina, também no celular:
 * uma etapa por aba, cartões grandes, um toque para avançar.
 */
final class ProductionController extends Controller
{
    public function __construct(
        private readonly ProductionJobRepository $jobs,
        private readonly ProductionSpecRepository $specs,
        private readonly ProductionService $production,
        private readonly ProductionPlanner $planner,
        private readonly Auth $auth,
    ) {
    }

    public function queue(Request $request): Response
    {
        $this->production->backfill();

        $stage = $request->queryString('etapa', 20);
        $stage = in_array($stage, [ProductionFlow::QUEUED, ...ProductionFlow::CANONICAL], true) ? $stage : '';
        $mine = $request->queryString('meus', 1) === '1';

        // A previsão considera a fila inteira; o filtro só escolhe o que mostrar
        $plan = $this->planner->plan($this->jobs->open(), now_utc(), today_local());
        $visible = array_values(array_filter($plan['jobs'], fn (array $j): bool =>
            ($stage === '' || $j['stage'] === $stage) && (!$mine || (int) $j['operator_user_id'] === $this->userId())));

        return $this->render('admin/production/queue', [
            'title' => 'Produção | Painel',
            'jobs' => $visible,
            'plan' => $plan,
            'stage' => $stage,
            'mine' => $mine,
            'counts' => $this->jobs->stageCounts(),
            'personalizations' => $this->jobs->personalizations(array_map(static fn (array $j): int => (int) $j['order_item_id'], $visible)),
            'capacity' => (int) config('production.daily_capacity_minutes', 420),
            'userId' => $this->userId(),
        ], 'admin');
    }

    public function show(Request $request): Response
    {
        $job = $this->job($request);
        $spec = $this->specs->findByVariant((int) $job['variant_id']);
        $route = array_values(array_filter(explode(',', (string) $job['route'])));

        return $this->render('admin/production/job', [
            'title' => 'Ordem de produção ' . $job['order_number'] . ' | Painel',
            'job' => $job,
            'route' => $route,
            'spec' => $spec,
            'steps' => $spec === null ? [] : $this->specs->steps((int) $spec['id']),
            'files' => $spec === null ? [] : $this->specs->files((int) $spec['id']),
            'personalization' => $this->jobs->personalizations([(int) $job['order_item_id']])[(int) $job['order_item_id']] ?? [],
            'events' => $this->jobs->events((int) $job['id']),
            'reworkTargets' => $job['stage'] === 'quality' ? ProductionFlow::reworkTargets($route) : [],
            'deadline' => $job['paid_at'] === null ? null : business_days_after((string) $job['paid_at'], (int) $job['production_days']),
        ], 'admin');
    }

    public function advance(Request $request): Response
    {
        $job = $this->job($request);
        try {
            $to = $this->production->advance((int) $job['id'], $this->userId(), mb_substr($request->string('note'), 0, 500));
            $this->flash('success', "{$job['order_number']} · {$job['product_name']}: " . ProductionFlow::label($to) . '.');
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->back($request, $job);
    }

    public function rework(Request $request): Response
    {
        $job = $this->job($request);
        try {
            $to = $this->production->rework((int) $job['id'], $request->string('to_stage'), $this->userId(), mb_substr($request->string('note'), 0, 500));
            $this->flash('success', "Retrabalho registrado: volta para " . ProductionFlow::label($to) . '.');
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->back($request, $job);
    }

    public function claim(Request $request): Response
    {
        $job = $this->job($request);
        try {
            $this->production->claim((int) $job['id'], $request->string('release') === '1' ? null : $this->userId());
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->back($request, $job);
    }

    /** @param array<string, mixed> $job */
    private function back(Request $request, array $job): Response
    {
        return $this->redirect($this->safeRedirectPath($request->string('back'), '/admin/producao/' . $job['id']));
    }

    /** @return array<string, mixed> */
    private function job(Request $request): array
    {
        return $this->jobs->find((int) $request->param('id')) ?? throw HttpException::notFound();
    }

    private function userId(): ?int
    {
        return isset($this->auth->admin()['user_id']) ? (int) $this->auth->admin()['user_id'] : null;
    }
}
