<?php

declare(strict_types=1);

namespace GNesting\Services\Auth;

use RuntimeException;

final class TooManyAttemptsException extends RuntimeException
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        $minutes = (int) max(1, ceil($retryAfterSeconds / 60));
        parent::__construct("Muitas tentativas. Tente novamente em {$minutes} minuto" . ($minutes > 1 ? 's' : '') . '.');
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
