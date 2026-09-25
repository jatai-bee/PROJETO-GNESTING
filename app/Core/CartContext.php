<?php

declare(strict_types=1);

namespace GNesting\Core;

/**
 * Token do carrinho da requisição atual.
 * Preenchido pelo middleware LoadCart a partir do cookie; quando o CartService cria
 * um carrinho novo, o token é marcado para ser gravado no cookie da resposta.
 * Assim os Services não dependem de HTTP.
 */
final class CartContext
{
    private ?string $token = null;
    private bool $issued = false;

    public function reset(?string $cookieToken): void
    {
        $this->token = self::isValid($cookieToken) ? $cookieToken : null;
        $this->issued = false;
    }

    public function token(): ?string
    {
        return $this->token;
    }

    /** Novo token (carrinho novo ou token inválido/expirado): deve ir para o cookie. */
    public function issue(string $token): void
    {
        $this->token = $token;
        $this->issued = true;
    }

    public function forget(): void
    {
        $this->token = null;
    }

    public function wasIssued(): bool
    {
        return $this->issued;
    }

    /** Tokens são 64 caracteres hexadecimais (32 bytes aleatórios). */
    public static function isValid(?string $token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }
}
