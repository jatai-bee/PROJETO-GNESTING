<?php

declare(strict_types=1);

namespace GNesting\Services\Auth;

/**
 * Argon2id quando disponível; bcrypt (cost 12) como alternativa em hospedagens sem Argon2.
 * Hashes antigos são atualizados de forma transparente no login (needsRehash).
 */
final class PasswordHasher
{
    private ?string $dummyHash = null;

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, $this->algorithm(), $this->options());
    }

    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm(), $this->options());
    }

    /**
     * Hash descartável usado quando o e-mail não existe, para que a resposta
     * leve o mesmo tempo (não revela quais e-mails estão cadastrados).
     */
    public function dummyHash(): string
    {
        return $this->dummyHash ??= $this->hash(bin2hex(random_bytes(16)));
    }

    private function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string, int> */
    private function options(): array
    {
        return defined('PASSWORD_ARGON2ID') ? [] : ['cost' => 12];
    }
}
