<?php

declare(strict_types=1);

namespace GNesting\Services;

use RuntimeException;

/**
 * Ação recusada por uma regra de negócio (ex.: excluir categoria com produtos).
 * A mensagem é segura para exibir ao administrador.
 */
final class BusinessRuleException extends RuntimeException
{
}
