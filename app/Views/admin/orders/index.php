<?php
/**
 * @var list<array<string, mixed>> $orders
 * @var \GNesting\Core\Paginator $paginator
 * @var array<string, string> $filters status, payment, q, de, ate
 * @var array<string, int> $counts status => quantidade
 * @var bool $cards visualização em cartões
 */
use GNesting\Enums\OrderStatus;
use GNesting\Enums\PaymentStatus;

$open = array_sum(array_diff_key($counts, ['delivered' => 0, 'cancelled' => 0]));
$tabs = ['' => ['Todos', array_sum($counts)], 'open' => ['Em aberto', $open]];
foreach ([OrderStatus::AwaitingPayment, OrderStatus::ProductionPending, OrderStatus::InProduction, OrderStatus::Finishing,
             OrderStatus::QualityControl, OrderStatus::Packaging, OrderStatus::ReadyToShip, OrderStatus::Shipped,
             OrderStatus::Delivered, OrderStatus::Cancelled] as $s) {
    $tabs[$s->value] = [$s->label(), $counts[$s->value] ?? 0];
}
$params = ['q' => $filters['q'], 'pagamento' => $filters['payment'], 'de' => $filters['de'], 'ate' => $filters['ate'], 'visao' => $cards ? 'cartoes' : null];
?>
<div class="page-header">
    <h1 class="page-title">Pedidos</h1>
    <nav class="segmented" aria-label="Visualização">
        <a href="<?= e(query_url('/admin/pedidos', ['visao' => null] + $params + ['status' => $filters['status']])) ?>"<?= !$cards ? ' aria-current="page"' : '' ?>>Tabela</a>
        <a href="<?= e(query_url('/admin/pedidos', ['visao' => 'cartoes'] + $params + ['status' => $filters['status']])) ?>"<?= $cards ? ' aria-current="page"' : '' ?>>Cartões</a>
    </nav>
</div>

<nav class="status-tabs" aria-label="Filtrar por situação">
    <?php foreach ($tabs as $value => [$label, $count]): ?>
        <?php if ($value !== '' && $value !== 'open' && $count === 0) { continue; } ?>
        <a href="<?= e(query_url('/admin/pedidos', $params + ['status' => $value])) ?>"<?= $filters['status'] === $value ? ' aria-current="page"' : '' ?>>
            <?= e($label) ?> <span><?= e($count) ?></span>
        </a>
    <?php endforeach ?>
</nav>

<section class="panel">
    <form method="get" action="<?= e(url('/admin/pedidos')) ?>" class="filters">
        <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <div class="field">
            <label for="q">Buscar</label>
            <input id="q" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Número, nome, e-mail, CPF ou telefone">
        </div>
        <div class="field">
            <label for="pagamento">Pagamento</label>
            <select id="pagamento" name="pagamento">
                <option value="">Todos</option>
                <?php foreach (PaymentStatus::cases() as $p): ?>
                    <option value="<?= e($p->value) ?>"<?= $filters['payment'] === $p->value ? ' selected' : '' ?>><?= e($p->label()) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field"><label for="de">De</label><input id="de" name="de" type="date" value="<?= e($filters['de']) ?>"></div>
        <div class="field"><label for="ate">Até</label><input id="ate" name="ate" type="date" value="<?= e($filters['ate']) ?>"></div>
        <div class="filters__actions">
            <button type="submit" class="btn btn--secondary btn--sm">Filtrar</button>
            <a href="<?= e(url('/admin/pedidos')) ?>">Limpar</a>
        </div>
    </form>

    <?php if ($orders === []): ?>
        <p class="muted">Nenhum pedido encontrado.</p>
    <?php else: ?>
        <p class="table-summary"><?= e($paginator->total) ?> pedido(s)</p>
        <?php if ($cards): ?>
        <div class="order-cards">
            <?php foreach ($orders as $order): ?>
                <?php
                $deadline = $order['paid_at'] !== null && !in_array($order['status'], ['shipped', 'delivered', 'cancelled'], true)
                    ? business_days_after((string) $order['paid_at'], (int) $order['production_days']) : null;
                $late = $deadline !== null && $deadline < today_local();
                ?>
                <a class="order-card order-card--<?= e($order['status']) ?><?= $late ? ' order-card--late' : '' ?>" href="<?= e(url('/admin/pedidos/' . $order['id'])) ?>">
                    <span class="order-card__top"><strong class="mono"><?= e($order['number']) ?></strong><?= $this->partial('admin/orders/status-badge', ['status' => $order['status']]) ?></span>
                    <span class="order-card__customer"><?= e($order['customer_name']) ?> <small class="muted">· <?= e($order['ship_city'] . '/' . $order['ship_state']) ?></small></span>
                    <span class="order-card__total"><?= e(money((int) $order['total_cents'])) ?> <small class="muted">· <?= e($order['item_count']) ?> <?= (int) $order['item_count'] === 1 ? 'item' : 'itens' ?><?= (int) $order['personalization_count'] > 0 ? ' · personalizado' : '' ?></small></span>
                    <span class="order-card__foot">
                        <span><?= e(format_datetime($order['placed_at'], 'd/m H:i')) ?></span>
                        <?php if ($deadline !== null): ?><span class="<?= $late ? 'text-danger' : '' ?>">produção até <?= e(implode('/', array_reverse(explode('-', substr($deadline, 5))))) ?></span><?php endif ?>
                    </span>
                </a>
            <?php endforeach ?>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Pedido</th><th>Cliente</th><th>Itens</th><th class="table__num">Total</th><th>Situação</th><th>Pagamento</th><th>Prazo de produção</th></tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <?php
                    $deadline = null;
                    if ($order['paid_at'] !== null && !in_array($order['status'], ['shipped', 'delivered', 'cancelled'], true)) {
                        $deadline = business_days_after((string) $order['paid_at'], (int) $order['production_days']);
                    }
                    ?>
                    <tr>
                        <td><a href="<?= e(url('/admin/pedidos/' . $order['id'])) ?>"><strong><?= e($order['number']) ?></strong></a><br>
                            <small class="muted"><?= e(format_datetime($order['placed_at'])) ?></small></td>
                        <td><?= e($order['customer_name']) ?><br><small class="muted"><?= e($order['ship_city'] . '/' . $order['ship_state']) ?></small></td>
                        <td><?= e($order['item_count']) ?><?php if ((int) $order['personalization_count'] > 0): ?> <span class="badge badge--accent" title="Tem personalização">Pers.</span><?php endif ?></td>
                        <td class="table__num nowrap"><?= e(money((int) $order['total_cents'])) ?></td>
                        <td><?= $this->partial('admin/orders/status-badge', ['status' => $order['status']]) ?></td>
                        <td><?= e(PaymentStatus::tryFrom($order['payment_status'])?->label() ?? $order['payment_status']) ?></td>
                        <td class="nowrap">
                            <?php if ($deadline !== null): ?>
                                <span class="<?= $deadline < today_local() ? 'status status--off' : '' ?>"><?= e(implode('/', array_reverse(explode('-', $deadline)))) ?></span>
                            <?php else: ?>—<?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>
        <?= $this->partial('partials/pagination', ['paginator' => $paginator, 'path' => '/admin/pedidos', 'params' => $params + ['status' => $filters['status']]]) ?>
    <?php endif ?>
</section>
