<?php /** @var array{name:string,email:string} $customer */ ?>
<section class="section">
    <div class="container">
        <span class="eyebrow">Minha conta</span>
        <h1 class="section__title">Olá, <?= e(explode(' ', $customer['name'])[0]) ?>.</h1>
        <dl class="details">
            <div><dt>Nome</dt><dd><?= e($customer['name']) ?></dd></div>
            <div><dt>E-mail</dt><dd><?= e($customer['email']) ?></dd></div>
        </dl>
        <p class="notice">Seus pedidos, endereços e favoritos aparecerão aqui.</p>
    </div>
</section>
