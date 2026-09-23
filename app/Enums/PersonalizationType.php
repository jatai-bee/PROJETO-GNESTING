<?php

declare(strict_types=1);

namespace GNesting\Enums;

enum PersonalizationType: string
{
    case Text = 'text';
    case Initial = 'initial';
    case Date = 'date';
    case Select = 'select';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texto curto',
            self::Initial => 'Inicial',
            self::Date => 'Data',
            self::Select => 'Opção pré-definida',
        };
    }
}
