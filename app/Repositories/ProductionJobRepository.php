<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/** Ordens de produção (jobs), eventos de etapa e dados da ficha usados na fila. */
final class ProductionJobRepository extends Repository
{
    private const JOB_FIELDS = 'j.id, j.order_id, j.order_item_id, j.variant_id, j.quantity, j.route, j.stage, j.operator_user_id,
        j.estimated_minutes, j.rework_count, j.stage_started_at, j.started_at, j.finished_at,
        o.number AS order_number, o.status AS order_status, o.paid_at, o.production_days, o.customer_name,
        oi.product_id, oi.product_name, oi.variant_name, oi.sku, a.name AS operator_name';

    private const JOB_FROM = ' FROM production_jobs j
        JOIN orders o ON o.id = j.order_id
        JOIN order_items oi ON oi.id = j.order_item_id
        LEFT JOIN admins a ON a.user_id = j.operator_user_id';

    /** @return list<array<string, mixed>> itens do pedido que ainda não têm job */
    public function itemsWithoutJob(int $orderId): array
    {
        return $this->fetchAll(
            'SELECT oi.id, oi.variant_id, oi.quantity, oi.production_minutes_estimate
               FROM order_items oi
               LEFT JOIN production_jobs j ON j.order_item_id = oi.id
              WHERE oi.order_id = :id AND j.id IS NULL ORDER BY oi.id',
            ['id' => $orderId]
        );
    }

    /**
     * Pedidos em produção sem jobs (pagos antes desta etapa existir).
     *
     * @return list<int>
     */
    public function ordersMissingJobs(): array
    {
        return array_map('intval', array_column($this->fetchAll(
            "SELECT DISTINCT o.id FROM orders o
               JOIN order_items oi ON oi.order_id = o.id
               LEFT JOIN production_jobs j ON j.order_item_id = oi.id
              WHERE o.status IN ('production_pending','in_production','finishing','quality_control','packaging') AND j.id IS NULL"
        ), 'id'));
    }

    /** @return list<string> etapas da ficha da variante, na ordem da ficha */
    public function specStages(int $variantId): array
    {
        return array_column($this->fetchAll(
            'SELECT s.stage FROM production_spec_steps s JOIN production_specs ps ON ps.id = s.spec_id
              WHERE ps.variant_id = :id ORDER BY s.sort_order, s.id',
            ['id' => $variantId]
        ), 'stage');
    }

    /**
     * Minutos de operador por etapa (por unidade), da ficha atual.
     *
     * @return array<string, int>
     */
    public function operatorMinutesByStage(int $variantId): array
    {
        $minutes = [];
        foreach ($this->fetchAll(
            'SELECT s.stage, SUM(IF(s.is_passive = 0, s.estimated_minutes, 0)) AS minutes
               FROM production_spec_steps s JOIN production_specs ps ON ps.id = s.spec_id
              WHERE ps.variant_id = :id GROUP BY s.stage',
            ['id' => $variantId]
        ) as $row) {
            $minutes[(string) $row['stage']] = (int) $row['minutes'];
        }

        return $minutes;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO production_jobs (order_id, order_item_id, variant_id, quantity, route, estimated_minutes)
             VALUES (:order_id, :order_item_id, :variant_id, :quantity, :route, :estimated_minutes)',
            $data
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $jobId, bool $forUpdate = false): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::JOB_FIELDS . self::JOB_FROM . ' WHERE j.id = :id' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['id' => $jobId]
        );
    }

    /** @return list<string> etapas atuais dos jobs do pedido */
    public function stagesForOrder(int $orderId): array
    {
        return array_column($this->fetchAll('SELECT stage FROM production_jobs WHERE order_id = :id', ['id' => $orderId]), 'stage');
    }

    public function countForOrder(int $orderId): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM production_jobs WHERE order_id = :id', ['id' => $orderId]);
    }

    /** @return list<array<string, mixed>> jobs do pedido (página do pedido) */
    public function forOrder(int $orderId): array
    {
        return $this->fetchAll('SELECT ' . self::JOB_FIELDS . self::JOB_FROM . ' WHERE j.order_id = :id ORDER BY j.id', ['id' => $orderId]);
    }

    /**
     * Fila: jobs em aberto, ordenados pelo pagamento (o prazo sai dele).
     *
     * @return list<array<string, mixed>>
     */
    public function open(?string $stage = null, ?int $operatorId = null): array
    {
        $sql = 'SELECT ' . self::JOB_FIELDS . self::JOB_FROM . " WHERE j.stage NOT IN ('done', 'cancelled')";
        $params = [];
        if ($stage !== null) {
            $sql .= ' AND j.stage = :stage';
            $params['stage'] = $stage;
        }
        if ($operatorId !== null) {
            $sql .= ' AND j.operator_user_id = :operator';
            $params['operator'] = $operatorId;
        }

        return $this->fetchAll($sql . ' ORDER BY o.paid_at, o.id, j.id', $params);
    }

    /** @return array<string, int> etapa => quantidade de jobs em aberto */
    public function stageCounts(): array
    {
        $counts = [];
        foreach ($this->fetchAll("SELECT stage, COUNT(*) AS total FROM production_jobs WHERE stage NOT IN ('done', 'cancelled') GROUP BY stage") as $row) {
            $counts[(string) $row['stage']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Personalizações dos itens (para a gravação), por order_item_id.
     *
     * @param list<int> $orderItemIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function personalizations(array $orderItemIds): array
    {
        if ($orderItemIds === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values($orderItemIds) as $n => $id) {
            $placeholders[] = ':i' . $n;
            $params['i' . $n] = $id;
        }
        $map = [];
        foreach ($this->fetchAll(
            'SELECT order_item_id, label, type, value_text, value_label FROM order_item_personalizations
              WHERE order_item_id IN (' . implode(', ', $placeholders) . ') ORDER BY id',
            $params
        ) as $row) {
            $map[(int) $row['order_item_id']][] = $row;
        }

        return $map;
    }

    public function moveTo(int $jobId, string $stage, bool $isRework): void
    {
        $this->execute(
            "UPDATE production_jobs
                SET stage = :stage, stage_started_at = UTC_TIMESTAMP(),
                    started_at = COALESCE(started_at, IF(:stage2 <> 'queued', UTC_TIMESTAMP(), NULL)),
                    finished_at = IF(:stage3 = 'done', UTC_TIMESTAMP(), NULL),
                    rework_count = rework_count + :rework
              WHERE id = :id",
            ['stage' => $stage, 'stage2' => $stage, 'stage3' => $stage, 'rework' => (int) $isRework, 'id' => $jobId]
        );
    }

    public function setOperator(int $jobId, ?int $userId): void
    {
        $this->execute('UPDATE production_jobs SET operator_user_id = :user WHERE id = :id', ['user' => $userId, 'id' => $jobId]);
    }

    public function cancelForOrder(int $orderId): void
    {
        $this->execute("UPDATE production_jobs SET stage = 'cancelled' WHERE order_id = :id AND stage <> 'done'", ['id' => $orderId]);
    }

    public function addEvent(int $jobId, ?string $from, string $to, ?int $userId, bool $isRework, ?string $note): void
    {
        $this->execute(
            'INSERT INTO production_job_events (job_id, from_stage, to_stage, user_id, is_rework, note)
             VALUES (:job, :from, :to, :user, :rework, :note)',
            ['job' => $jobId, 'from' => $from, 'to' => $to, 'user' => $userId, 'rework' => (int) $isRework, 'note' => $note]
        );
    }

    /** @return list<array<string, mixed>> */
    public function events(int $jobId): array
    {
        return $this->fetchAll(
            'SELECT e.from_stage, e.to_stage, e.is_rework, e.note, e.created_at, a.name AS user_name
               FROM production_job_events e LEFT JOIN admins a ON a.user_id = e.user_id
              WHERE e.job_id = :id ORDER BY e.id',
            ['id' => $jobId]
        );
    }
}
