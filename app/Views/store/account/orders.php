<?php
/** @var list<array<string, mixed>> $orders */
?>
<div class="container">
    <header class="page-head">
        <span class="eyebrow">Minha conta</span>
        <h1 class="page-head__title">Meus pedidos</h1>
    </header>
    <div class="account">
        <?= $this->partial('store/account/nav', ['active' => 'pedidos']) ?>
        <div><?= $this->partial('store/account/order-list', ['orders' => $orders]) ?></div>
    </div>
</div>
