<?php

declare(strict_types=1);

namespace GNesting\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Authorized => 'Autorizado',
            self::Paid => 'Pago',
            self::Refunded => 'Estornado',
            self::PartiallyRefunded => 'Estornado parcialmente',
            self::Failed => 'Recusado',
            self::Cancelled => 'Cancelado',
        };
    }
}
