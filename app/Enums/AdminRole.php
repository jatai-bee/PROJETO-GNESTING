<?php

declare(strict_types=1);

namespace GNesting\Enums;

enum AdminRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Production = 'production';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Proprietário',
            self::Manager => 'Gestor',
            self::Production => 'Produção',
            self::Support => 'Atendimento',
        };
    }

    /**
     * O proprietário acessa tudo; os demais papéis só o que a rota permitir.
     *
     * @param list<string> $allowed valores de papel aceitos pela rota
     */
    public function isAllowed(array $allowed): bool
    {
        return $this === self::Owner || in_array($this->value, $allowed, true);
    }
}
