<?php
/**
 * @var array{name:string,email:string} $customer
 * @var list<array<string, mixed>> $orders últimos pedidos
 * @var list<int> $favoriteIds
 */
$icon = fn (string $name): string => $this->partial('partials/icon', ['name' => $name, 'size' => 24]);
$favCount = count($favoriteIds ?? []);
?>
<div class="container">
    <header class="page-head">
        <span class="eyebrow">Minha conta</span>
        <h1 class="page-head__title">Olá, <?= e(explode(' ', $customer['name'])[0]) ?>.</h1>
        <p class="page-head__intro"><?= e($customer['name']) ?> · <?= e($customer['email']) ?></p>
    </header>
    <div class="account">
        <?= $this->partial('store/account/nav', ['active' => 'inicio']) ?>
        <div>
            <div class="account-cards">
                <a class="account-card" href="<?= e(url('/conta/pedidos')) ?>"><?= $icon('box') ?><strong>Meus pedidos</strong><span>Acompanhe produção, envio e entrega.</span></a>
                <a class="account-card" href="<?= e(url('/conta/favoritos')) ?>"><?= $icon('heart') ?><strong>Favoritos<?= $favCount > 0 ? ' (' . e($favCount) . ')' : '' ?></strong><span>Os produtos que você guardou para depois.</span></a>
                <a class="account-card" href="<?= e(url('/produtos')) ?>"><?= $icon('sparkle') ?><strong>Novidades</strong><span>Veja o que acabou de sair do ateliê.</span></a>
            </div>

            <div class="section__head">
                <h2 class="section__title">Últimos pedidos</h2>
                <?php if ($orders !== []): ?><a class="link-arrow" href="<?= e(url('/conta/pedidos')) ?>">Ver todos</a><?php endif ?>
            </div>
            <?= $this->partial('store/account/order-list', ['orders' => $orders]) ?>
            <p class="field__hint">Pedidos feitos sem entrar na conta são acompanhados pelo link enviado por e-mail.</p>
        </div>
    </div>
</div>
