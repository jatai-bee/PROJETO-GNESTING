<?php

declare(strict_types=1);

namespace GNesting\Enums;

/**
 * Status do pedido e matriz de transições permitidas.
 * Ver docs/03-status-e-fluxo-de-producao.md. A aplicação das transições
 * (histórico, auditoria, efeitos) será feita pelo OrderStatusService (etapa 8).
 */
enum OrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case ProductionPending = 'production_pending';
    case InProduction = 'in_production';
    case Finishing = 'finishing';
    case QualityControl = 'quality_control';
    case Packaging = 'packaging';
    case ReadyToShip = 'ready_to_ship';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Aguardando pagamento',
            self::Paid => 'Pagamento aprovado',
            self::ProductionPending => 'Produção pendente',
            self::InProduction => 'Em produção',
            self::Finishing => 'Acabamento',
            self::QualityControl => 'Controle de qualidade',
            self::Packaging => 'Embalagem',
            self::ReadyToShip => 'Pronto para envio',
            self::Shipped => 'Enviado',
            self::Delivered => 'Entregue',
            self::Cancelled => 'Cancelado',
        };
    }

    /** Rótulo exibido ao cliente (sem jargão interno de produção). */
    public function customerLabel(): string
    {
        return match ($this) {
            self::ProductionPending => 'Na fila de produção',
            self::InProduction, self::Finishing, self::QualityControl => 'Em produção',
            self::Packaging, self::ReadyToShip => 'Preparando o envio',
            default => $this->label(),
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::AwaitingPayment => [self::Paid, self::Cancelled],
            self::Paid => [self::ProductionPending, self::Cancelled],
            self::ProductionPending => [self::InProduction, self::Cancelled],
            self::InProduction => [self::Finishing, self::Cancelled],
            self::Finishing => [self::QualityControl, self::Cancelled],
            self::QualityControl => [self::Packaging, self::InProduction, self::Cancelled],
            self::Packaging => [self::ReadyToShip, self::Cancelled],
            self::ReadyToShip => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
