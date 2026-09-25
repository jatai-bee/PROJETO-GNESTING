<?php
/**
 * @var array{name:string,email:string} $customer
 * @var list<array<string, mixed>> $orders últimos pedidos
 */
?>
<section class="section">
    <div class="container">
        <span class="eyebrow">Minha conta</span>
        <h1 class="section__title">Olá, <?= e(explode(' ', $customer['name'])[0]) ?>.</h1>
        <dl class="details">
            <div><dt>Nome</dt><dd><?= e($customer['name']) ?></dd></div>
            <div><dt>E-mail</dt><dd><?= e($customer['email']) ?></dd></div>
        </dl>

        <div class="section__head account-orders-head">
            <h2 class="section__title">Últimos pedidos</h2>
            <?php if ($orders !== []): ?><a href="<?= e(url('/conta/pedidos')) ?>">Ver todos</a><?php endif ?>
        </div>
        <?= $this->partial('store/account/order-list', ['orders' => $orders]) ?>
        <p class="field__hint">Pedidos feitos sem entrar na conta são acompanhados pelo link enviado por e-mail.</p>
    </div>
</section>
