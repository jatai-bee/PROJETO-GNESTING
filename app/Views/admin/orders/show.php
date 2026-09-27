<?php
/**
 * @var array<string, mixed>       $order
 * @var list<array<string, mixed>> $items       com 'personalization'
 * @var list<array<string, mixed>> $payments
 * @var array<string, mixed>|null  $shipment
 * @var list<array<string, mixed>> $history
 * @var list<array<string, mixed>> $notes       internas e mensagens ao cliente
 * @var list<\GNesting\Enums\OrderStatus> $targets transições permitidas ao papel (sem cancelamento)
 * @var bool $canCancel
 * @var bool $canMessage
 * @var bool $isPaid
 * @var bool $fullCpf
 * @var array{role: string} $currentAdmin
 */
use GNesting\Enums\AdminRole;
use GNesting\Enums\OrderStatus;
use GNesting\Enums\PaymentStatus;
use GNesting\Helpers\BrazilianDocument;
use GNesting\Helpers\ZipCode;

$base = '/admin/pedidos/' . $order['id'];
$methods = ['pix' => 'Pix', 'credit_card' => 'Cartão', 'boleto' => 'Boleto', 'checkout' => 'Não escolhido', 'other' => 'Outro'];
$sources = ['customer' => 'cliente', 'system' => 'sistema', 'webhook' => 'pagamento', 'admin' => 'painel', 'return' => 'retorno do pagamento'];
$whatsapp = whatsapp_url($order['customer_phone'], "Olá, {$order['customer_name']}! Aqui é da G-Nesting, sobre o seu pedido {$order['number']}.");
$canSeeCustomers = AdminRole::tryFrom($currentAdmin['role'])?->isAllowed(['manager', 'support']) ?? false;
$deadline = $order['paid_at'] !== null ? business_days_after((string) $order['paid_at'], (int) $order['production_days']) : null;
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= e($order['number']) ?> <?= $this->partial('admin/orders/status-badge', ['status' => $order['status']]) ?></h1>
        <p class="muted">Feito em <?= e(format_datetime($order['placed_at'])) ?>
            <?php if ($order['paid_at']): ?> · pago em <?= e(format_datetime($order['paid_at'])) ?><?php endif ?>
            <?php if ($deadline !== null && !in_array($order['status'], ['shipped', 'delivered', 'cancelled'], true)): ?>
                · produção até <strong<?= $deadline < today_local() ? ' class="text-danger"' : '' ?>><?= e(implode('/', array_reverse(explode('-', $deadline)))) ?></strong>
            <?php endif ?></p>
    </div>
</div>

<?php if ($order['status'] !== OrderStatus::Cancelled->value): ?>
    <?php
    // Andamento completo (o cliente vê 5 marcos; aqui aparecem todas as etapas)
    $flow = [OrderStatus::AwaitingPayment, OrderStatus::Paid, OrderStatus::ProductionPending, OrderStatus::InProduction, OrderStatus::Finishing,
        OrderStatus::QualityControl, OrderStatus::Packaging, OrderStatus::ReadyToShip, OrderStatus::Shipped, OrderStatus::Delivered];
    $position = array_search(OrderStatus::from((string) $order['status']), $flow, true);
    ?>
    <ol class="route order-route" aria-label="Andamento do pedido">
        <?php foreach ($flow as $n => $step): ?>
            <?php $state = $n < $position ? 'done' : ($n === $position ? 'current' : 'todo'); ?>
            <li class="route__step route__step--<?= e($state) ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>><?= e($step->label()) ?></li>
        <?php endforeach ?>
    </ol>
<?php endif ?>

<div class="order-admin">
    <div class="order-admin__main">
        <section class="panel">
            <h2 class="panel__title">Itens</h2>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Produto</th><th>SKU</th><th class="table__num">Qtd.</th><th class="table__num">Unitário</th><th class="table__num">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <strong><?= e($item['product_name']) ?></strong><?php if ($item['variant_name']): ?> · <?= e($item['variant_name']) ?><?php endif ?>
                                <?php foreach ($item['personalization'] as $choice): ?>
                                    <div class="personalization-line">
                                        <?= e($choice['label']) ?>:
                                        <strong class="personalization-line__value"><?= e($choice['value_label'] ?? ($choice['type'] === 'date' ? implode('/', array_reverse(explode('-', (string) $choice['value_text']))) : $choice['value_text'])) ?></strong>
                                        <?php if ((int) $choice['price_delta_cents'] > 0): ?><small class="muted">+<?= e(money((int) $choice['price_delta_cents'])) ?></small><?php endif ?>
                                    </div>
                                <?php endforeach ?>
                            </td>
                            <td><code><?= e($item['sku']) ?></code></td>
                            <td class="table__num"><?= e($item['quantity']) ?></td>
                            <td class="table__num nowrap"><?= e(money((int) $item['unit_price_cents'] + (int) $item['personalization_cents'])) ?></td>
                            <td class="table__num nowrap"><?= e(money((int) $item['line_total_cents'])) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="4">Subtotal</td><td class="table__num nowrap"><?= e(money((int) $order['subtotal_cents'])) ?></td></tr>
                        <tr><td colspan="4">Frete · <?= e($order['shipping_service']) ?> (<?= e($order['shipping_days']) ?> dias úteis)</td><td class="table__num nowrap"><?= e(money((int) $order['shipping_cents'])) ?></td></tr>
                        <tr><th colspan="4">Total</th><th class="table__num nowrap"><?= e(money((int) $order['total_cents'])) ?></th></tr>
                    </tfoot>
                </table>
            </div>
            <p class="muted">Prazo de produção prometido: <?= e($order['production_days']) ?> dias úteis após o pagamento.</p>
        </section>

        <section class="panel">
            <h2 class="panel__title">Pagamento <small class="muted"><?= e(PaymentStatus::tryFrom($order['payment_status'])?->label() ?? $order['payment_status']) ?></small></h2>
            <?php if ($payments === []): ?>
                <p class="muted">Nenhuma tentativa de pagamento.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Quando</th><th>Provedor</th><th>Meio</th><th>Situação</th><th class="table__num">Valor</th></tr></thead>
                        <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td class="nowrap"><?= e(format_datetime($payment['created_at'])) ?></td>
                                <td><?= e($payment['provider']) ?></td>
                                <td><?= e($methods[$payment['method']] ?? $payment['method']) ?>
                                    <?php if ($payment['card_last4']): ?> ···· <?= e($payment['card_last4']) ?><?php endif ?>
                                    <?php if ((int) $payment['installments'] > 1): ?> · <?= e($payment['installments']) ?>x<?php endif ?></td>
                                <td><?= e(PaymentStatus::tryFrom($payment['status'])?->label() ?? $payment['status']) ?></td>
                                <td class="table__num nowrap"><?= e(money((int) $payment['amount_cents'])) ?></td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </section>

        <section class="panel">
            <h2 class="panel__title">Notas e mensagens</h2>
            <?php if ($notes === []): ?>
                <p class="muted">Nenhuma nota ainda.</p>
            <?php else: ?>
                <ol class="notes">
                    <?php foreach ($notes as $note): ?>
                        <li class="notes__item notes__item--<?= e($note['visibility']) ?>">
                            <small class="muted">
                                <?= e(format_datetime($note['created_at'])) ?> · <?= e($note['author_name'] ?? 'Sistema') ?> ·
                                <?= $note['visibility'] === 'customer' ? 'mensagem ao cliente' . ($note['emailed_at'] ? ' (e-mail enviado)' : ' (e-mail NÃO enviado)') : 'interna' ?>
                            </small>
                            <p><?= nl2br(e($note['body']), false) ?></p>
                        </li>
                    <?php endforeach ?>
                </ol>
            <?php endif ?>

            <form method="post" action="<?= e(url($base . '/nota')) ?>" class="note-form">
                <?= csrf_field() ?>
                <label for="note-body">Nota interna <small class="muted">(só a equipe vê)</small></label>
                <textarea id="note-body" name="body" rows="2" maxlength="2000" required></textarea>
                <button type="submit" class="btn btn--secondary btn--sm">Registrar nota</button>
            </form>

            <?php if ($canMessage): ?>
                <form method="post" action="<?= e(url($base . '/mensagem')) ?>" class="note-form"
                      data-confirm="Enviar esta mensagem por e-mail para <?= e($order['customer_email']) ?>? Ela também aparece na página do pedido.">
                    <?= csrf_field() ?>
                    <label for="message-body">Mensagem ao cliente <small class="muted">(e-mail + página do pedido)</small></label>
                    <textarea id="message-body" name="body" rows="3" maxlength="2000" required></textarea>
                    <button type="submit" class="btn btn--primary btn--sm">Enviar ao cliente</button>
                </form>
            <?php endif ?>
        </section>

        <section class="panel">
            <h2 class="panel__title">Histórico</h2>
            <ol class="timeline-admin">
                <?php foreach ($history as $step): ?>
                    <li>
                        <strong><?= e(OrderStatus::tryFrom($step['to_status'])?->label() ?? $step['to_status']) ?></strong>
                        <small class="muted"><?= e(format_datetime($step['created_at'])) ?> · <?= e($sources[$step['source']] ?? $step['source']) ?></small>
                        <?php if ($step['note']): ?><br><small><?= e($step['note']) ?></small><?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
        </section>
    </div>

    <aside class="order-admin__side">
        <?php if ($jobs !== []): ?>
            <section class="panel">
                <h2 class="panel__title">Produção</h2>
                <p class="muted">As etapas de produção andam pela fila: o pedido acompanha o item mais atrasado.</p>
                <ul class="steps-list">
                    <?php foreach ($jobs as $job): ?>
                        <li><?= e($job['quantity']) ?> × <?= e($job['product_name']) ?> —
                            <?php if ($canSeeProduction): ?>
                                <a href="<?= e(url('/admin/producao/' . $job['id'])) ?>"><?= e(\GNesting\Services\Production\ProductionFlow::label((string) $job['stage'])) ?></a>
                            <?php else: ?>
                                <?= e(\GNesting\Services\Production\ProductionFlow::label((string) $job['stage'])) ?>
                            <?php endif ?>
                            <?php if ((int) $job['rework_count'] > 0): ?><span class="status status--warn">retrabalho ×<?= e($job['rework_count']) ?></span><?php endif ?></li>
                    <?php endforeach ?>
                </ul>
            </section>
        <?php endif ?>

        <?php if ($targets !== [] || $canCancel): ?>
            <section class="panel">
                <h2 class="panel__title">Próximo passo</h2>
                <?php foreach ($targets as $target): ?>
                    <form method="post" action="<?= e(url($base . '/status')) ?>" class="step-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="target" value="<?= e($target->value) ?>">
                        <?php if ($target === OrderStatus::Shipped): ?>
                            <div class="field"><label for="carrier">Transportadora</label>
                                <input id="carrier" name="carrier" value="<?= e($order['shipping_carrier']) ?>" maxlength="60"></div>
                            <div class="field"><label for="tracking_code">Código de rastreio</label>
                                <input id="tracking_code" name="tracking_code" maxlength="60" placeholder="AA123456789BR"></div>
                            <div class="field"><label for="tracking_url">Link de rastreio <span class="field__optional">(opcional)</span></label>
                                <input id="tracking_url" name="tracking_url" type="url" maxlength="250" placeholder="https://"></div>
                        <?php endif ?>
                        <div class="field"><label for="note-<?= e($target->value) ?>">Observação <span class="field__optional">(opcional)</span></label>
                            <input id="note-<?= e($target->value) ?>" name="note" maxlength="500"></div>
                        <button type="submit" class="btn btn--<?= $target === OrderStatus::InProduction && $order['status'] === 'quality_control' ? 'secondary' : 'primary' ?> btn--block">
                            <?= $target === OrderStatus::InProduction && $order['status'] === 'quality_control' ? 'Voltar para produção (retrabalho)' : 'Mover para: ' . e($target->label()) ?>
                        </button>
                    </form>
                <?php endforeach ?>

                <?php if ($canCancel): ?>
                    <details class="cancel-box">
                        <summary>Cancelar pedido</summary>
                        <form method="post" action="<?= e(url($base . '/cancelar')) ?>" data-confirm="Cancelar o pedido <?= e($order['number']) ?>? O cliente será avisado por e-mail.">
                            <?= csrf_field() ?>
                            <div class="field"><label for="reason">Motivo <span class="muted">(vai para o cliente)</span></label>
                                <input id="reason" name="reason" maxlength="200" required></div>
                            <?php if ($isPaid): ?>
                                <fieldset class="field">
                                    <legend>Devolução do valor (<?= e(money((int) $order['total_cents'])) ?>)</legend>
                                    <label class="checkbox"><input type="radio" name="refund" value="gateway" checked> Estornar agora pelo provedor de pagamento</label>
                                    <label class="checkbox"><input type="radio" name="refund" value="manual"> Já estornei por fora (registrar)</label>
                                </fieldset>
                                <p class="field__hint">Itens de pronta entrega voltam ao estoque.</p>
                            <?php else: ?>
                                <p class="field__hint">Pedido não pago: a reserva de estoque é liberada.</p>
                            <?php endif ?>
                            <button type="submit" class="btn btn--danger btn--sm">Cancelar pedido</button>
                        </form>
                    </details>
                <?php endif ?>
            </section>
        <?php endif ?>

        <?php if ($shipment !== null): ?>
            <section class="panel">
                <h2 class="panel__title">Envio</h2>
                <p><?= e($shipment['carrier']) ?><?= $shipment['service'] ? ' · ' . e($shipment['service']) : '' ?><br>
                    <?php if ($shipment['tracking_code']): ?>Rastreio: <code><?= e($shipment['tracking_code']) ?></code><br><?php endif ?>
                    Enviado em <?= e(format_datetime($shipment['shipped_at'])) ?>
                    <?php if ($shipment['delivered_at']): ?><br>Entregue em <?= e(format_datetime($shipment['delivered_at'])) ?><?php endif ?></p>
            </section>
        <?php endif ?>

        <section class="panel">
            <h2 class="panel__title">Cliente</h2>
            <p><strong><?= e($order['customer_name']) ?></strong><br>
                <?= e($order['customer_email']) ?><br>
                <?= e(BrazilianDocument::formatPhone($order['customer_phone'])) ?><br>
                CPF <?= e($fullCpf ? BrazilianDocument::formatCpf($order['customer_cpf']) : BrazilianDocument::maskCpf($order['customer_cpf'])) ?></p>
            <div class="actions actions--stack">
                <?php if ($whatsapp !== null): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= e($whatsapp) ?>" target="_blank" rel="noopener noreferrer">Abrir WhatsApp</a>
                <?php endif ?>
                <?php if ($canMessage): ?>
                    <form method="post" action="<?= e(url($base . '/reenviar-link')) ?>" class="inline-form">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn--secondary btn--sm">Reenviar link do pedido</button>
                    </form>
                <?php endif ?>
                <?php if ($canSeeCustomers): ?>
                    <a href="<?= e(url('/admin/clientes/' . $order['customer_id'])) ?>">Ver cliente</a>
                <?php endif ?>
            </div>

            <h3 class="panel__subtitle">Entrega</h3>
            <p><?= e($order['ship_recipient']) ?><br>
                <?= e("{$order['ship_street']}, {$order['ship_number']}" . ($order['ship_complement'] ? " — {$order['ship_complement']}" : '')) ?><br>
                <?= e("{$order['ship_district']} · {$order['ship_city']}/{$order['ship_state']}") ?><br>
                CEP <?= e(ZipCode::format((string) $order['ship_zip_code'])) ?></p>
        </section>
    </aside>
</div>
