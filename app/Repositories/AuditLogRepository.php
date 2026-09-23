<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/** Trilha de auditoria: somente inserção e leitura. */
final class AuditLogRepository extends Repository
{
    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public function create(
        ?int $userId,
        string $action,
        ?string $entityType,
        ?int $entityId,
        ?array $old,
        ?array $new,
        ?string $ip,
        ?string $userAgent,
    ): int {
        return $this->insert(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
             VALUES (:user_id, :action, :entity_type, :entity_id, :old_values, :new_values, :ip, :ua)',
            [
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'new_values' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ip' => $ip,
                'ua' => $userAgent,
            ]
        );
    }

    /**
     * @param array{action?: string, entity_type?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(array $filters): array
    {
        $sql = ' WHERE 1 = 1';
        $params = [];
        if (($filters['action'] ?? '') !== '') {
            $sql .= ' AND l.action = :action';
            $params['action'] = $filters['action'];
        }
        if (($filters['entity_type'] ?? '') !== '') {
            $sql .= ' AND l.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }

        return [$sql, $params];
    }

    /** @param array{action?: string, entity_type?: string} $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->where($filters);

        return (int) $this->fetchValue('SELECT COUNT(*) FROM audit_logs l' . $where, $params);
    }

    /**
     * @param array{action?: string, entity_type?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function paginate(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filters);

        return $this->fetchAll(
            'SELECT l.id, l.action, l.entity_type, l.entity_id, l.old_values, l.new_values, l.ip_address,
                    l.user_agent, l.created_at, a.name AS admin_name, u.email AS user_email
               FROM audit_logs l
               LEFT JOIN users u ON u.id = l.user_id
               LEFT JOIN admins a ON a.user_id = l.user_id'
            . $where . ' ORDER BY l.id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
    }

    /** @return list<string> */
    public function distinctEntityTypes(): array
    {
        return array_column(
            $this->fetchAll('SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type'),
            'entity_type'
        );
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $limit = 10): array
    {
        return $this->fetchAll(
            'SELECT l.id, l.action, l.entity_type, l.entity_id, l.ip_address, l.created_at, a.name AS admin_name
               FROM audit_logs l
               LEFT JOIN admins a ON a.user_id = l.user_id
              ORDER BY l.id DESC
              LIMIT :limit',
            ['limit' => max(1, min($limit, 100))]
        );
    }
}
