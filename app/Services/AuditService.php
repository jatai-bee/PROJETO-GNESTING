<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\AuditContext;
use GNesting\Repositories\AuditLogRepository;

/**
 * Registra eventos administrativos em audit_logs (ver docs/05-seguranca.md §13).
 * IP, navegador e usuário vêm do AuditContext da requisição atual.
 */
final class AuditService
{
    public const LOGIN = 'login';
    public const LOGOUT = 'logout';
    public const LOGIN_FAILED = 'login_failed';
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const PRICE_CHANGE = 'price_change';
    public const STOCK_CHANGE = 'stock_change';
    public const STATUS_CHANGE = 'status_change';
    public const PASSWORD_RESET = 'password_reset';
    public const EXPORT = 'export';
    public const ANONYMIZE = 'anonymize';

    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly AuditContext $context,
    ) {
    }

    /**
     * Registra só os campos que mudaram. Não grava nada se não houve mudança.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return bool se houve mudança
     */
    public function recordChanges(string $action, string $entityType, int $entityId, array $before, array $after): bool
    {
        $old = [];
        $new = [];
        foreach ($after as $field => $value) {
            $previous = $before[$field] ?? null;
            if ((string) json_encode($previous) !== (string) json_encode($value)) {
                $old[$field] = $previous;
                $new[$field] = $value;
            }
        }

        if ($new === []) {
            return false;
        }
        $this->record($action, $entityType, $entityId, $old, $new);

        return true;
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $old = null,
        ?array $new = null,
        ?int $userId = null,
    ): void {
        $this->repository->create(
            $userId ?? $this->context->userId(),
            $action,
            $entityType,
            $entityId,
            $old,
            $new,
            $this->context->ip(),
            $this->context->userAgent(),
        );
    }
}
