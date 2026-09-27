<?php
/** @var list<array<string, mixed>> $products favoritos ainda visíveis na loja */
?>
<div class="container">
    <header class="page-head">
        <span class="eyebrow">Minha conta</span>
        <h1 class="page-head__title">Meus favoritos</h1>
    </header>
    <div class="account">
        <?= $this->partial('store/account/nav', ['active' => 'favoritos']) ?>
        <div>
            <?php if ($products === []): ?>
                <div class="empty-state">
                    <span class="empty-state__icon"><?= $this->partial('partials/icon', ['name' => 'heart', 'size' => 30]) ?></span>
                    <h2 class="empty-state__title">Nenhum favorito ainda</h2>
                    <p>Toque no coração dos produtos para guardá-los aqui e comprar quando quiser.</p>
                    <a class="btn btn--primary" href="<?= e(url('/produtos')) ?>">Ver produtos</a>
                </div>
            <?php else: ?>
                <div class="product-grid product-grid--3">
                    <?php foreach ($products as $product): ?>
                        <?= $this->partial('partials/product-card', ['product' => $product]) ?>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>
    </div>
</div>
