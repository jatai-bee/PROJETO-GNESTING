<?php
/**
 * Página do pedido (confirmação e acompanhamento).
 * @var array<string, mixed>       $order
 * @var list<array<string, mixed>> $items
 * @var list<array<string, mixed>> $payments
 * @var list<array<string, mixed>> $history
 * @var string $accessKey   chave do link privado (repassada ao "Pagar agora")
 * @var string $returnStatus retorno do provedor (só informativo)
 */
use GNesting\Enums\OrderStatus;
use GNesting\Helpers\ZipCode;

$status = OrderStatus::from((string) $order['status']);
$awaiting = $status === OrderStatus::AwaitingPayment;
$methods = ['pix' => 'Pix', 'credit_card' => 'Cartão', 'boleto' => 'Boleto', 'checkout' => 'A escolher', 'other' => 'Outro'];
$lastPayment = $payments === [] ? null : $payments[array_key_last($payments)];
?>
<div class="container">
    <header class="page-head">
        <span class="eyebrow">Pedido</span>
        <h1 class="page-head__title"><?= e($order['number']) ?></h1>
        <p class="page-head__intro">Feito em <?= e(format_datetime($order['placed_at'])) ?> · <?= e($order['customer_email']) ?></p>
    </header>

    <section class="order-status order-status--<?= e($status->value) ?>" aria-live="polite">
        <p class="order-status__label"><?= e($status->customerLabel()) ?></p>
        <?php if ($awaiting): ?>
            <?php if ($lastPayment !== null && $lastPayment['status'] === 'failed'): ?>
                <p>O pagamento não foi aprovado. Você pode tentar de novo com outro meio.</p>
            <?php elseif ($returnStatus === 'pendente' || ($lastPayment !== null && $lastPayment['status'] === 'pending' && $lastPayment['method'] !== 'checkout')): ?>
                <p>Estamos aguardando a confirmação do pagamento. Pix costuma ser confirmado em minutos; boleto, em até 3 dias úteis.</p>
            <?php else: ?>
                <p>Seu pedido está reservado. Conclua o pagamento para ele entrar na fila de produção.</p>
            <?php endif ?>
            <form method="post" action="<?= e(url('/pedido/' . $order['number'] . '/pagar')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="chave" value="<?= e($accessKey) ?>">
                <button type="submit" class="btn btn--primary">Pagar agora</button>
            </form>
            <p class="field__hint">Pedidos não pagos em <?= e(config('payment.expiry_hours', 48)) ?> horas são cancelados e os itens voltam para a loja.</p>
        <?php elseif ($status === OrderStatus::Cancelled): ?>
            <p><?= e($order['cancel_reason'] ?: 'Este pedido foi cancelado.') ?></p>
        <?php else: ?>
            <p>Pagamento confirmado. Seu pedido entrou na fila de produção: fica pronto em até <?= e($order['production_days']) ?> dias úteis e depois segue para entrega.</p>
        <?php endif ?>
    </section>

    <?php if ($accessKey !== ''): ?>
        <p class="notice">Guarde o link desta página (também enviado para o seu e-mail): é por ele que você acompanha o pedido.</p>
    <?php endif ?>

    <div class="order-grid">
        <section class="panel-box" aria-labelledby="itens">
            <h2 id="itens" class="panel-box__title">Itens</h2>
            <ul class="summary-items">
                <?php foreach ($items as $item): ?>
                    <li>
                        <span>
                            <?= e($item['quantity']) ?> ×
                            <?php if ($item['product_slug']): ?><a href="<?= e(url('/produto/' . $item['product_slug'])) ?>"><?= e($item['product_name']) ?></a><?php else: ?><?= e($item['product_name']) ?><?php endif ?>
                            <?php if ($item['variant_name']): ?><small class="muted">(<?= e($item['variant_name']) ?>)</small><?php endif ?>
                            <?php foreach ($item['personalization'] as $choice): ?>
                                <br><small class="muted"><?= e($choice['label']) ?>: <?= e($choice['value_label'] ?? ($choice['type'] === 'date' ? implode('/', array_reverse(explode('-', (string) $choice['value_text']))) : $choice['value_text'])) ?></small>
                            <?php endforeach ?>
                        </span>
                        <span><?= e(money((int) $item['line_total_cents'])) ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
            <dl class="summary-lines">
                <div><dt>Subtotal</dt><dd><?= e(money((int) $order['subtotal_cents'])) ?></dd></div>
                <div><dt>Frete (<?= e($order['shipping_service']) ?>)</dt><dd><?= (int) $order['shipping_cents'] === 0 ? 'Grátis' : e(money((int) $order['shipping_cents'])) ?></dd></div>
                <div class="summary-lines__total"><dt>Total</dt><dd><?= e(money((int) $order['total_cents'])) ?></dd></div>
            </dl>
        </section>

        <section class="panel-box" aria-labelledby="entrega">
            <h2 id="entrega" class="panel-box__title">Entrega</h2>
            <p>
                <strong><?= e($order['ship_recipient']) ?></strong><br>
                <?= e("{$order['ship_street']}, {$order['ship_number']}" . ($order['ship_complement'] ? " — {$order['ship_complement']}" : '')) ?><br>
                <?= e("{$order['ship_district']}, {$order['ship_city']}/{$order['ship_state']}") ?><br>
                CEP <?= e(ZipCode::format((string) $order['ship_zip_code'])) ?>
            </p>
            <p class="muted"><?= e($order['shipping_service']) ?> · até <?= e($order['shipping_days']) ?> dias úteis após a produção (<?= e($order['production_days']) ?> dias úteis).</p>

            <?php if ($lastPayment !== null && $lastPayment['status'] !== 'pending'): ?>
                <h2 class="panel-box__title">Pagamento</h2>
                <p><?= e($methods[$lastPayment['method']] ?? $lastPayment['method']) ?>
                    <?php if ($lastPayment['card_last4']): ?>· final <?= e($lastPayment['card_last4']) ?><?php endif ?>
                    <?php if ((int) $lastPayment['installments'] > 1): ?>· <?= e($lastPayment['installments']) ?>x<?php endif ?>
                    · <?= e(\GNesting\Enums\PaymentStatus::tryFrom($lastPayment['status'])?->label() ?? $lastPayment['status']) ?></p>
            <?php endif ?>

            <h2 class="panel-box__title">Andamento</h2>
            <ol class="timeline">
                <?php foreach ($history as $step): ?>
                    <li><strong><?= e(OrderStatus::tryFrom($step['to_status'])?->customerLabel() ?? $step['to_status']) ?></strong>
                        <small class="muted"><?= e(format_datetime($step['created_at'])) ?></small></li>
                <?php endforeach ?>
            </ol>
        </section>
    </div>

    <p><a href="<?= e(url('/produtos')) ?>">Continuar comprando</a></p>
</div>
