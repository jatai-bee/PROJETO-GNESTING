<?php

declare(strict_types=1);

namespace GNesting\Core;

/**
 * Quem está agindo na requisição atual (IP, navegador, usuário).
 * Preenchido pelo Kernel e pelo middleware de autenticação; lido pelo AuditService,
 * para que os Services não dependam de HTTP.
 */
final class AuditContext
{
    private ?string $ip = null;
    private ?string $userAgent = null;
    private ?int $userId = null;

    public function reset(?string $ip, ?string $userAgent): void
    {
        $this->ip = $ip;
        $this->userAgent = $userAgent;
        $this->userId = null;
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }
}
