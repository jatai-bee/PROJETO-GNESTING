<?php
/** @var list<array<string, mixed>> $orders */
?>
<div class="container">
    <header class="page-head">
        <a class="eyebrow" href="<?= e(url('/conta')) ?>">← Minha conta</a>
        <h1 class="page-head__title">Meus pedidos</h1>
    </header>
    <?= $this->partial('store/account/order-list', ['orders' => $orders]) ?>
</div>
