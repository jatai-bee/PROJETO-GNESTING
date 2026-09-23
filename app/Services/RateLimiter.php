<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Repositories\RateLimitRepository;
use GNesting\Services\Auth\TooManyAttemptsException;

/**
 * Limite de tentativas persistido no banco (hospedagem compartilhada não tem Redis).
 */
final class RateLimiter
{
    public function __construct(private readonly RateLimitRepository $repository)
    {
    }

    /** @throws TooManyAttemptsException se a chave estiver bloqueada */
    public function ensureNotBlocked(string $key): void
    {
        $seconds = $this->repository->blockedSeconds($key);
        if ($seconds > 0) {
            throw new TooManyAttemptsException($seconds);
        }
    }

    public function hit(string $key, int $maxAttempts, int $windowSeconds): void
    {
        $this->repository->hit($key, $maxAttempts, $windowSeconds);
    }

    public function clear(string $key): void
    {
        $this->repository->clear($key);
    }
}
