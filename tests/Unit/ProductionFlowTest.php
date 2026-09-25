<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Enums\OrderStatus;
use GNesting\Services\Production\ProductionFlow;
use PHPUnit\Framework\TestCase;

final class ProductionFlowTest extends TestCase
{
    public function testRouteUsesCanonicalOrderAndAlwaysIncludesQualityAndPackaging(): void
    {
        self::assertSame(['cnc', 'sanding', 'painting', 'drying', 'assembly', 'quality', 'packaging'],
            ProductionFlow::route(['packaging', 'drying', 'cnc', 'quality', 'assembly', 'sanding', 'painting', 'cnc']));
        self::assertSame(['quality', 'packaging'], ProductionFlow::route([]), 'Sem ficha: conferência e embalagem');
        self::assertSame(['cnc', 'quality', 'packaging'], ProductionFlow::route(['cnc', 'laser-inventado']));
    }

    public function testNextAndReworkTargets(): void
    {
        $route = ['cnc', 'sanding', 'quality', 'packaging'];
        self::assertSame('cnc', ProductionFlow::next($route, 'queued'));
        self::assertSame('quality', ProductionFlow::next($route, 'sanding'));
        self::assertSame('done', ProductionFlow::next($route, 'packaging'));
        self::assertSame(['cnc', 'sanding'], ProductionFlow::reworkTargets($route));
        self::assertSame([], ProductionFlow::reworkTargets(['quality', 'packaging']));
    }

    public function testOrderStatusFollowsTheSlowestJob(): void
    {
        self::assertSame(OrderStatus::ProductionPending, ProductionFlow::orderStatus(['queued', 'packaging']));
        self::assertSame(OrderStatus::InProduction, ProductionFlow::orderStatus(['done', 'cnc', 'quality']));
        self::assertSame(OrderStatus::Finishing, ProductionFlow::orderStatus(['drying', 'quality']));
        self::assertSame(OrderStatus::QualityControl, ProductionFlow::orderStatus(['quality', 'done']));
        self::assertSame(OrderStatus::Packaging, ProductionFlow::orderStatus(['packaging', 'done']));
        self::assertSame(OrderStatus::ReadyToShip, ProductionFlow::orderStatus(['done', 'done']));
        self::assertSame(OrderStatus::ReadyToShip, ProductionFlow::orderStatus(['done', 'cancelled']), 'Job cancelado não segura o pedido');
    }

    public function testLabels(): void
    {
        self::assertSame('Na fila', ProductionFlow::label('queued'));
        self::assertSame('CNC', ProductionFlow::label('cnc'));
        self::assertSame('Pronto', ProductionFlow::label('done'));
        self::assertSame('Iniciar CNC', ProductionFlow::actionLabel('queued', 'cnc'));
        self::assertSame('Aprovar → Embalagem', ProductionFlow::actionLabel('quality', 'packaging'));
        self::assertSame('Concluir → Lixamento', ProductionFlow::actionLabel('cnc', 'sanding'));
    }
}
