<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    public function testHappyPathFollowsProductionFlow(): void
    {
        $flow = [
            OrderStatus::AwaitingPayment, OrderStatus::Paid, OrderStatus::ProductionPending,
            OrderStatus::InProduction, OrderStatus::Finishing, OrderStatus::QualityControl,
            OrderStatus::Packaging, OrderStatus::ReadyToShip, OrderStatus::Shipped, OrderStatus::Delivered,
        ];

        for ($i = 0; $i < count($flow) - 1; $i++) {
            self::assertTrue($flow[$i]->canTransitionTo($flow[$i + 1]), "{$flow[$i]->value} → {$flow[$i + 1]->value}");
        }
    }

    public function testCannotSkipSteps(): void
    {
        self::assertFalse(OrderStatus::AwaitingPayment->canTransitionTo(OrderStatus::InProduction));
        self::assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::Shipped));
    }

    public function testQualityControlCanSendBackForRework(): void
    {
        self::assertTrue(OrderStatus::QualityControl->canTransitionTo(OrderStatus::InProduction));
    }

    public function testCancellationOnlyUntilReadyToShip(): void
    {
        self::assertTrue(OrderStatus::ReadyToShip->canTransitionTo(OrderStatus::Cancelled));
        self::assertFalse(OrderStatus::Shipped->canTransitionTo(OrderStatus::Cancelled));
        self::assertFalse(OrderStatus::Delivered->canTransitionTo(OrderStatus::Cancelled));
    }

    public function testFinalStatuses(): void
    {
        self::assertTrue(OrderStatus::Delivered->isFinal());
        self::assertTrue(OrderStatus::Cancelled->isFinal());
        self::assertFalse(OrderStatus::Shipped->isFinal());
    }

    public function testEveryStatusHasPortugueseLabel(): void
    {
        foreach (OrderStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
        }
        self::assertSame('Aguardando pagamento', OrderStatus::AwaitingPayment->label());
    }
}
