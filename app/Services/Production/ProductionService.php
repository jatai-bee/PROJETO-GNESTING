<?php

declare(strict_types=1);

namespace GNesting\Services\Production;

use GNesting\Core\Database;
use GNesting\Repositories\MaterialRepository;
use GNesting\Repositories\ProductionJobRepository;
use GNesting\Repositories\ProductionSpecRepository;
use GNesting\Services\AuditService;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\OrderStatusService;

/**
 * Fila de produção (docs/03 §6, docs/14):
 * - pedido pago → um job por item, com a rota congelada da ficha;
 * - avançar / retrabalho (só a partir do controle de qualidade) / assumir;
 * - ao sair do CNC, a matéria-prima é consumida (quantidade ÷ peças por chapa);
 * - após cada movimento, o status do pedido acompanha o job mais atrasado.
 */
final class ProductionService
{
    public function __construct(
        private readonly Database $db,
        private readonly ProductionJobRepository $jobs,
        private readonly ProductionSpecRepository $specs,
        private readonly MaterialRepository $materials,
        private readonly OrderStatusService $status,
        private readonly AuditService $audit,
    ) {
    }

    /** Cria os jobs que faltam para o pedido (idempotente). @return int jobs criados */
    public function createJobs(int $orderId): int
    {
        return $this->db->transaction(function () use ($orderId): int {
            $created = 0;
            foreach ($this->jobs->itemsWithoutJob($orderId) as $item) {
                $route = ProductionFlow::route($this->jobs->specStages((int) $item['variant_id']));
                $perUnit = $item['production_minutes_estimate'] === null ? null : (int) $item['production_minutes_estimate'];
                $jobId = $this->jobs->create([
                    'order_id' => $orderId,
                    'order_item_id' => (int) $item['id'],
                    'variant_id' => (int) $item['variant_id'],
                    'quantity' => (int) $item['quantity'],
                    'route' => implode(',', $route),
                    'estimated_minutes' => $perUnit === null ? null : $perUnit * (int) $item['quantity'],
                ]);
                $this->jobs->addEvent($jobId, null, ProductionFlow::QUEUED, null, false, 'Entrou na fila');
                $created++;
            }

            return $created;
        });
    }

    /** Pedidos pagos antes da fila existir ganham seus jobs. @return int pedidos ajustados */
    public function backfill(): int
    {
        $orders = $this->jobs->ordersMissingJobs();
        foreach ($orders as $orderId) {
            $this->createJobs($orderId);
        }

        return count($orders);
    }

    /** Próxima etapa da rota. @throws BusinessRuleException */
    public function advance(int $jobId, ?int $userId, ?string $note = null): string
    {
        return $this->move($jobId, $userId, $note, null);
    }

    /** Controle de qualidade reprova: volta para uma etapa anterior. @throws BusinessRuleException */
    public function rework(int $jobId, string $toStage, ?int $userId, string $note): string
    {
        if (trim($note) === '') {
            throw new BusinessRuleException('Descreva o motivo do retrabalho.');
        }

        return $this->move($jobId, $userId, $note, $toStage);
    }

    public function claim(int $jobId, ?int $userId): void
    {
        $job = $this->jobs->find($jobId) ?? throw new BusinessRuleException('Ordem de produção não encontrada.');
        if (in_array($job['stage'], [ProductionFlow::DONE, ProductionFlow::CANCELLED], true)) {
            throw new BusinessRuleException('Esta ordem já saiu da fila.');
        }
        $this->jobs->setOperator($jobId, $userId);
    }

    private function move(int $jobId, ?int $userId, ?string $note, ?string $reworkTo): string
    {
        [$orderId, $to, $from] = $this->db->transaction(function () use ($jobId, $userId, $note, $reworkTo): array {
            $job = $this->jobs->find($jobId, true) ?? throw new BusinessRuleException('Ordem de produção não encontrada.');
            $from = (string) $job['stage'];
            $route = array_values(array_filter(explode(',', (string) $job['route'])));

            if (in_array($from, [ProductionFlow::DONE, ProductionFlow::CANCELLED], true)) {
                throw new BusinessRuleException('Esta ordem já saiu da fila.');
            }
            if (!in_array($job['order_status'], array_map(static fn ($s) => $s->value, OrderStatusService::PRODUCTION_RANGE), true)) {
                throw new BusinessRuleException('O pedido não está em produção.');
            }

            if ($reworkTo !== null) {
                if ($from !== 'quality' || !in_array($reworkTo, ProductionFlow::reworkTargets($route), true)) {
                    throw new BusinessRuleException('Retrabalho só a partir do controle de qualidade, para uma etapa anterior da rota.');
                }
                $to = $reworkTo;
            } else {
                $to = ProductionFlow::next($route, $from);
            }

            $this->jobs->moveTo($jobId, $to, $reworkTo !== null);
            $this->jobs->addEvent($jobId, $from, $to, $userId, $reworkTo !== null, $note === null || $note === '' ? null : mb_substr($note, 0, 500));
            if ($job['operator_user_id'] === null && $userId !== null) {
                $this->jobs->setOperator($jobId, $userId);
            }
            if ($from === 'cnc' && $reworkTo === null) {
                $this->consumeMaterial($job, $userId);
            }
            $this->audit->record(AuditService::STATUS_CHANGE, 'production_job', $jobId,
                ['stage' => $from], ['stage' => $to] + ($reworkTo !== null ? ['rework' => $note] : []));

            return [(int) $job['order_id'], $to, $from];
        });

        $this->syncOrder($orderId, $userId, $reworkTo !== null ? 'Retrabalho: ' . $note : null);

        return $to;
    }

    /** Pedido acompanha o job mais atrasado. */
    public function syncOrder(int $orderId, ?int $userId, ?string $note = null): void
    {
        $stages = $this->jobs->stagesForOrder($orderId);
        if ($stages !== []) {
            $this->status->syncProduction($orderId, ProductionFlow::orderStatus($stages), $userId, $note);
        }
    }

    /**
     * Chapas usadas = quantidade ÷ peças por chapa (2 casas), do material da ficha da variante.
     *
     * @param array<string, mixed> $job
     */
    private function consumeMaterial(array $job, ?int $userId): void
    {
        $spec = $this->specs->findByVariant((int) $job['variant_id']);
        if ($spec === null || empty($spec['material_id']) || empty($spec['pieces_per_sheet'])) {
            return;
        }
        // Centésimos de chapa, arredondando para cima (sem ponto flutuante)
        $hundredths = intdiv((int) $job['quantity'] * 100 + (int) $spec['pieces_per_sheet'] - 1, (int) $spec['pieces_per_sheet']);
        $quantity = sprintf('-%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
        $this->materials->move((int) $spec['material_id'], $quantity,
            "Consumo no CNC: {$job['order_number']} · {$job['sku']} × {$job['quantity']}", 'production_job', (int) $job['id'], $userId);
    }
}
