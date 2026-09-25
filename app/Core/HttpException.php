<?php

declare(strict_types=1);

namespace GNesting\Core;

use RuntimeException;

/**
 * Erro HTTP esperado (404, 403, 419, 429...). A mensagem é segura para o usuário.
 */
final class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function notFound(): self
    {
        return new self(404, 'Página não encontrada.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'Você não tem permissão para acessar esta página.');
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'Método não permitido.', ['Allow' => implode(', ', $allowed)]);
    }

    public static function csrf(): self
    {
        return new self(419, 'Sua sessão expirou. Volte, recarregue a página e tente novamente.');
    }

    public static function tooManyRequests(int $retryAfterSeconds): self
    {
        return new self(429, 'Muitas tentativas. Aguarde alguns minutos e tente novamente.', [
            'Retry-After' => (string) max(1, $retryAfterSeconds),
        ]);
    }

    public static function maintenance(string $message = ''): self
    {
        return new self(503, $message !== '' ? $message : 'Estamos fazendo uma atualização rápida na loja. Volte em alguns minutos.', [
            'Retry-After' => '600',
        ]);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
