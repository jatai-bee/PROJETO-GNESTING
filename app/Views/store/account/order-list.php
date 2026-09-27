<?php
/** @var list<array<string, mixed>> $orders */
use GNesting\Enums\OrderStatus;
?>
<?php if ($orders === []): ?>
    <div class="empty-state">
        <span class="empty-state__icon"><?= $this->partial('partials/icon', ['name' => 'box', 'size' => 30]) ?></span>
        <p>Você ainda não fez pedidos com esta conta.</p>
        <a class="btn btn--primary" href="<?= e(url('/produtos')) ?>">Ver produtos</a>
    </div>
<?php else: ?>
    <ul class="order-list">
        <?php foreach ($orders as $order): ?>
            <li>
                <a class="order-list__item" href="<?= e(url('/pedido/' . $order['number'] . '/confirmacao')) ?>">
                    <span><strong><?= e($order['number']) ?></strong><br>
                        <small class="muted"><?= e(format_datetime($order['placed_at'], 'd/m/Y')) ?> · <?= e($order['item_count']) ?> <?= (int) $order['item_count'] === 1 ? 'item' : 'itens' ?></small></span>
                    <span class="order-list__status"><?= e(OrderStatus::tryFrom($order['status'])?->customerLabel() ?? $order['status']) ?></span>
                    <strong><?= e(money((int) $order['total_cents'])) ?></strong>
                </a>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
