<?php
/**
 * Menu lateral da conta (vira faixa rolável no celular).
 * @var string $active inicio|pedidos|favoritos
 */
$links = ['inicio' => ['/conta', 'Resumo'], 'pedidos' => ['/conta/pedidos', 'Meus pedidos'], 'favoritos' => ['/conta/favoritos', 'Favoritos']];
?>
<nav class="account-nav" aria-label="Minha conta">
    <?php foreach ($links as $key => [$href, $label]): ?>
        <a href="<?= e(url($href)) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach ?>
    <form method="post" action="<?= e(url('/sair')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--ghost btn--sm">Sair da conta</button>
    </form>
</nav>
