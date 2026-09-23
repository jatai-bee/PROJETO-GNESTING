<?php

declare(strict_types=1);

namespace GNesting\Enums;

enum ProductionStage: string
{
    case Cnc = 'cnc';
    case Sanding = 'sanding';
    case Painting = 'painting';
    case Drying = 'drying';
    case Assembly = 'assembly';
    case Quality = 'quality';
    case Packaging = 'packaging';

    public function label(): string
    {
        return match ($this) {
            self::Cnc => 'CNC',
            self::Sanding => 'Lixamento',
            self::Painting => 'Pintura / acabamento',
            self::Drying => 'Secagem',
            self::Assembly => 'Montagem',
            self::Quality => 'Controle de qualidade',
            self::Packaging => 'Embalagem',
        };
    }
}
