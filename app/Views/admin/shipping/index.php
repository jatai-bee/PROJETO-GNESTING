<?php
/**
 * @var list<array<string, mixed>> $ready     prontos para envio (com items, weight_g, full)
 * @var list<array<string, mixed>> $inTransit enviados aguardando entrega
 * @var list<array<string, mixed>> $delivered últimos entregues
 * @var array<string, int> $counts por situação
 */
use GNesting\Helpers\ZipCode;
?>
<div class="page-header">
    <h1 class="page-title">Expedição</h1>
    <a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/producao')) ?>">Produção</a>
</div>

<?php $inProduction = array_sum(array_intersect_key($counts, array_flip(['paid', 'production_pending', 'in_production', 'finishing', 'quality_control', 'packaging']))); ?>
<ol class="pipeline ship-pipeline" aria-label="Caminho do pedido até o cliente">
    <li class="pipeline__step<?= $inProduction > 0 ? ' pipeline__step--busy' : '' ?>"><a href="<?= e(url('/admin/producao')) ?>"><span class="pipeline__count"><?= e($inProduction) ?></span><span class="pipeline__label">Ainda na produção</span></a></li>
    <li class="pipeline__step<?= count($ready) > 0 ? ' pipeline__step--busy' : '' ?>"><a href="#prontos"><span class="pipeline__count"><?= e(count($ready)) ?></span><span class="pipeline__label">Prontos para envio</span></a></li>
    <li class="pipeline__step<?= count($inTransit) > 0 ? ' pipeline__step--busy' : '' ?>"><a href="#transito"><span class="pipeline__count"><?= e(count($inTransit)) ?></span><span class="pipeline__label">Em trânsito</span></a></li>
    <li class="pipeline__step"><a href="#entregues"><span class="pipeline__count"><?= e($counts['delivered'] ?? 0) ?></span><span class="pipeline__label">Entregues</span></a></li>
</ol>

<section class="panel" id="prontos">
    <h2 class="panel__title">Prontos para envio <small class="muted">(<?= e(count($ready)) ?>)</small></h2>
    <?php if ($ready === []): ?>
        <p class="muted">Nenhum pedido aguardando envio.</p>
    <?php endif ?>
    <div class="ship-list">
        <?php foreach ($ready as $order): ?>
            <?php $o = $order['full']; ?>
            <article class="ship-card">
                <header class="ship-card__head">
                    <a href="<?= e(url('/admin/pedidos/' . $order['id'])) ?>"><strong><?= e($order['number']) ?></strong></a>
                    <a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/expedicao/' . $order['id'] . '/romaneio')) ?>" target="_blank" rel="noopener">Romaneio</a>
                </header>
                <p><strong><?= e($o['ship_recipient']) ?></strong><br>
                    <?= e("{$o['ship_street']}, {$o['ship_number']}" . ($o['ship_complement'] ? " — {$o['ship_complement']}" : '')) ?><br>
                    <?= e("{$o['ship_district']} · {$o['ship_city']}/{$o['ship_state']} · CEP " . ZipCode::format((string) $o['ship_zip_code'])) ?></p>
                <ul class="steps-list">
                    <?php foreach ($order['items'] as $item): ?>
                        <li><?= e($item['quantity']) ?> × <?= e($item['product_name']) ?><?= $item['variant_name'] ? ' · ' . e($item['variant_name']) : '' ?></li>
                    <?php endforeach ?>
                </ul>
                <p class="muted"><?= e($o['shipping_service']) ?> · ≈ <?= e(format_decimal(number_format($order['weight_g'] / 1000, 2, '.', ''))) ?> kg</p>
                <form method="post" action="<?= e(url('/admin/expedicao/' . $order['id'] . '/enviar')) ?>" class="ship-form">
                    <?= csrf_field() ?>
                    <input name="carrier" value="<?= e($o['shipping_carrier']) ?>" aria-label="Transportadora" maxlength="60">
                    <input name="tracking_code" placeholder="Código de rastreio" aria-label="Código de rastreio" maxlength="60">
                    <input name="tracking_url" type="url" placeholder="Link de rastreio (opcional)" aria-label="Link de rastreio" maxlength="250">
                    <button type="submit" class="btn btn--primary btn--sm">Despachar</button>
                </form>
            </article>
        <?php endforeach ?>
    </div>
</section>

<section class="panel" id="transito">
    <h2 class="panel__title">Em trânsito <small class="muted">(<?= e(count($inTransit)) ?>)</small></h2>
    <?php if ($inTransit === []): ?>
        <p class="muted">Nenhum pedido em trânsito.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Pedido</th><th>Cliente</th><th>Destino</th><th><span class="visually-hidden">Ações</span></th></tr></thead>
                <tbody>
                <?php foreach ($inTransit as $order): ?>
                    <tr>
                        <td><a href="<?= e(url('/admin/pedidos/' . $order['id'])) ?>"><?= e($order['number']) ?></a></td>
                        <td><?= e($order['customer_name']) ?></td>
                        <td><?= e($order['ship_city'] . '/' . $order['ship_state']) ?></td>
                        <td class="table__actions">
                            <form method="post" action="<?= e(url('/admin/expedicao/' . $order['id'] . '/entregue')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn--secondary btn--sm">Marcar entregue</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>

<section class="panel" id="entregues">
    <div class="panel__header">
        <h2 class="panel__title">Últimos entregues</h2>
        <a class="link-arrow" href="<?= e(url('/admin/pedidos?status=delivered')) ?>">Todos os entregues</a>
    </div>
    <?php if ($delivered === []): ?>
        <p class="muted">Nenhuma entrega registrada ainda.</p>
    <?php else: ?>
        <ul class="order-feed">
            <?php foreach ($delivered as $order): ?>
                <li><a href="<?= e(url('/admin/pedidos/' . $order['id'])) ?>">
                    <span class="order-feed__main"><strong class="mono"><?= e($order['number']) ?></strong><small><?= e($order['customer_name']) ?> · <?= e($order['ship_city'] . '/' . $order['ship_state']) ?></small></span>
                    <span class="order-feed__side"><strong><?= e(money((int) $order['total_cents'])) ?></strong><span class="order-status-tag order-status-tag--delivered">Entregue</span></span>
                </a></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>
