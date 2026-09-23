<?php

declare(strict_types=1);

namespace GNesting\Core;

use RuntimeException;

/**
 * Entrada inválida. O Kernel converte em redirecionamento para o formulário
 * (com erros e valores antigos na sessão) ou em JSON 422.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors campo => mensagem
     * @param array<string, mixed>  $old    valores para repreencher o formulário
     */
    public function __construct(
        private readonly array $errors,
        private readonly array $old = [],
    ) {
        parent::__construct('Dados inválidos.');
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function old(): array
    {
        return $this->old;
    }
}
