<?php

declare(strict_types=1);

namespace GNesting\Services\Auth;

use RuntimeException;

/** Credenciais inválidas. A mensagem é genérica de propósito. */
final class AuthenticationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('E-mail ou senha inválidos.');
    }
}
