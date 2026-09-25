<?php
/**
 * Página de pagamento SIMULADA (somente desenvolvimento).
 * @var array<string, mixed> $payment
 * @var array<string, mixed> $order
 */
?>
<div class="container">
    <section class="simulated-pay">
        <span class="eyebrow">Ambiente de desenvolvimento</span>
        <h1 class="page-head__title">Pagamento simulado</h1>
        <p class="alert alert--warn">Esta página substitui o Mercado Pago enquanto <code>PAYMENT_PROVIDER=simulado</code>. Ela não existe em produção.</p>
        <p>Pedido <strong><?= e($order['number']) ?></strong> · <?= e(money((int) $payment['amount_cents'])) ?></p>
        <form method="post" action="<?= e(url('/pagamento-simulado/' . $payment['checkout_reference'])) ?>" class="actions">
            <?= csrf_field() ?>
            <button type="submit" name="resultado" value="aprovar" class="btn btn--primary">Aprovar pagamento</button>
            <button type="submit" name="resultado" value="pendente" class="btn btn--secondary">Deixar pendente (Pix/boleto)</button>
            <button type="submit" name="resultado" value="recusar" class="btn btn--secondary">Recusar</button>
        </form>
    </section>
</div>
