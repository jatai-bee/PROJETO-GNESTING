<?php
/**
 * @var array{items: list<array>, subtotal_cents: int, quantity: int, lead_days: int, has_issues: bool} $cart
 */
$issueText = [
    'unavailable' => 'Este produto não está mais disponível. Remova-o para continuar.',
    'out_of_stock' => 'Esgotado no momento. Remova-o para continuar.',
    'insufficient_stock' => 'Quantidade acima do disponível. Ajuste para continuar.',
    'personalization_invalid' => 'A personalização deste item mudou na loja. Remova-o e adicione de novo.',
];
?>
<div class="container">
    <header class="page-head">
        <h1 class="page-head__title">Carrinho</h1>
    </header>

    <?php if ($cart['items'] === []): ?>
        <div class="empty-state">
            <p>Seu carrinho está vazio.</p>
            <a class="btn btn--primary" href="<?= e(url('/produtos')) ?>">Ver produtos</a>
        </div>
    <?php else: ?>
    <div class="cart">
        <ul class="cart__items" aria-label="Itens do carrinho">
            <?php foreach ($cart['items'] as $item): ?>
                <li class="cart-item <?= $item['issue'] !== null ? 'cart-item--issue' : '' ?>">
                    <?php if (!isset($item['name'])): ?>
                        <div class="cart-item__body">
                            <p class="cart-item__title">Produto indisponível</p>
                            <p class="field__error"><?= e($issueText['unavailable']) ?></p>
                        </div>
                    <?php else: ?>
                        <a class="cart-item__media" href="<?= e(url('/produto/' . $item['slug'])) ?>" tabindex="-1" aria-hidden="true">
                            <?php if (!empty($item['cover_path'])): ?>
                                <img src="<?= e(upload_url($item['cover_path'], 400)) ?>" alt="" width="400" height="400" loading="lazy">
                            <?php else: ?>
                                <span class="media-placeholder"><img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="32" height="32"></span>
                            <?php endif ?>
                        </a>
                        <div class="cart-item__body">
                            <p class="cart-item__title"><a href="<?= e(url('/produto/' . $item['slug'])) ?>"><?= e($item['name']) ?></a></p>
                            <?php if (!empty($item['variant_name'])): ?>
                                <p class="muted"><?= e($item['variant_name']) ?></p>
                            <?php endif ?>
                            <?php if ($item['personalization'] !== []): ?>
                                <dl class="cart-item__personalization">
                                    <?php foreach ($item['personalization'] as $choice): ?>
                                        <div><dt><?= e($choice['label']) ?>:</dt> <dd><?= e($choice['display']) ?></dd></div>
                                    <?php endforeach ?>
                                </dl>
                            <?php endif ?>
                            <p class="cart-item__unit muted">
                                <?= e(money($item['unit_price_cents'])) ?> cada
                                <?php if ($item['personalization_cents'] > 0): ?>
                                    <span>(<?= e(money($item['base_price_cents'])) ?> + <?= e(money($item['personalization_cents'])) ?> de personalização)</span>
                                <?php endif ?>
                            </p>
                            <?php if ($item['issue'] !== null): ?>
                                <p class="field__error"><?= e($issueText[$item['issue']]) ?></p>
                            <?php endif ?>

                            <?php if ($item['issue'] !== 'unavailable' && $item['issue'] !== 'out_of_stock'): ?>
                            <form class="cart-item__qty" method="post" action="<?= e(url('/carrinho/itens/' . $item['id'])) ?>">
                                <?= csrf_field() ?>
                                <label for="qtd-<?= e($item['id']) ?>" class="visually-hidden">Quantidade de <?= e($item['name']) ?></label>
                                <input id="qtd-<?= e($item['id']) ?>" type="number" name="quantity" value="<?= e($item['quantity']) ?>"
                                       min="0" max="<?= e($item['max_quantity']) ?>" inputmode="numeric" data-autosubmit>
                                <button type="submit" class="btn btn--secondary btn--sm">Atualizar</button>
                            </form>
                            <?php endif ?>
                        </div>
                        <p class="cart-item__total">
                            <?php if ($item['issue'] === null): ?>
                                <?= e(money($item['line_total_cents'])) ?>
                            <?php else: ?>
                                <span class="muted" aria-label="Fora do total">—</span>
                            <?php endif ?>
                        </p>
                    <?php endif ?>
                    <form class="cart-item__remove" method="post" action="<?= e(url('/carrinho/itens/' . $item['id'] . '/remover')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="link-button">Remover<span class="visually-hidden"> <?= e($item['name'] ?? 'item') ?></span></button>
                    </form>
                </li>
            <?php endforeach ?>
        </ul>

        <aside class="cart__summary" aria-labelledby="resumo">
            <h2 id="resumo" class="cart__summary-title">Resumo</h2>
            <dl class="summary-lines">
                <div><dt>Subtotal (<?= e($cart['quantity']) ?> <?= $cart['quantity'] === 1 ? 'item' : 'itens' ?>)</dt><dd><?= e(money($cart['subtotal_cents'])) ?></dd></div>
                <div><dt>Frete</dt><dd class="muted">calculado no checkout</dd></div>
            </dl>
            <?php if ($cart['lead_days'] > 0): ?>
                <p class="field__hint">Produção em até <?= e($cart['lead_days']) ?> dia<?= $cart['lead_days'] > 1 ? 's' : '' ?> úte<?= $cart['lead_days'] > 1 ? 'is' : 'il' ?> + prazo de entrega.</p>
            <?php endif ?>
            <?php if ($cart['has_issues']): ?>
                <p class="alert alert--warn">Ajuste os itens destacados para continuar.</p>
            <?php endif ?>
            <?php if ($cart['has_issues']): ?>
                <button type="button" class="btn btn--primary btn--block" disabled>Finalizar compra</button>
            <?php else: ?>
                <a class="btn btn--primary btn--block" href="<?= e(url('/checkout')) ?>">Finalizar compra</a>
            <?php endif ?>
            <p class="field__hint">Frete calculado pelo CEP na próxima etapa. Compre sem precisar criar conta.</p>
            <a class="cart__continue" href="<?= e(url('/produtos')) ?>">Continuar comprando</a>
        </aside>
    </div>
    <?php endif ?>
</div>
