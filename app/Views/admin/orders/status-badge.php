<?php
/** @var string $status código do OrderStatus */
use GNesting\Enums\OrderStatus;

$enum = OrderStatus::tryFrom($status);
$modifier = match ($enum) {
    OrderStatus::AwaitingPayment => 'warn',
    OrderStatus::Cancelled => 'off',
    OrderStatus::Delivered, OrderStatus::Shipped => 'on',
    OrderStatus::ReadyToShip => 'ready',
    default => 'progress',
};
?>
<span class="status status--<?= e($modifier) ?>"><?= e($enum?->label() ?? $status) ?></span>
