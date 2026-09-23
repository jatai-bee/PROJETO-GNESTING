<?php

declare(strict_types=1);

namespace GNesting\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Blocked => 'Bloqueado',
        };
    }
}
