<?php

declare(strict_types=1);

namespace GNesting\Services\Auth;

use RuntimeException;

final class InvalidResetTokenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este link de redefinição é inválido ou já expirou. Peça um novo.');
    }
}
