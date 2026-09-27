<?php
/**
 * Carrinho: itens com quantidade (− +), personalização, avisos por item, cupom e resumo fixo.
 * @var array{items: list<array>, subtotal_cents: int, quantity: int, lead_days: int, has_issues: bool} $cart
 * @var list<array>|null $suggestions
 */
$issueText = [
    'unavailable' => 'Este produto não está mais disponível. Remova-o para continuar.',
    'out_of_stock' => 'Esgotado no momento. Remova-o para continuar.',
    'insufficient_stock' => 'Quantidade acima do disponível. Ajuste para continuar.',
    'personalization_invalid' => 'A personalização deste item mudou na loja. Remova-o e adicione de novo.',
];
$icon = fn (string $name, int $size = 20): string => $this->partial('partials/icon', ['name' => $name, 'size' => $size]);
?>
<div class="container">
    <header class="page-head">
        <h1 class="page-head__title">Carrinho</h1>
    </header>

    <?php if ($cart['items'] === []): ?>
        <div class="empty-state">
            <span class="empty-state__icon"><?= $icon('bag', 30) ?></span>
            <h2 class="empty-state__title">Seu carrinho está vazio</h2>
            <p>Seu carrinho está vazio. Que tal começar pelos destaques da loja?</p>
            <a class="btn btn--primary" href="<?= e(url('/produtos')) ?>">Ver produtos</a>
        </div>
    <?php else: ?>
    <ol class="steps checkout-steps" aria-label="Etapas da compra">
        <li class="is-current" aria-current="step">Carrinho</li>
        <li>Entrega e dados</li>
        <li>Pagamento</li>
        <li>Confirmação</li>
    </ol>
    <div class="cart">
        <ul class="cart-items" aria-label="Itens do carrinho">
            <?php foreach ($cart['items'] as $item): ?>
                <li class="cart-item<?= $item['issue'] !== null ? ' cart-item--issue' : '' ?>">
                    <?php if (!isset($item['name'])): ?>
                        <span class="cart-item__media"><span class="media-placeholder"><img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="32" height="32"></span></span>
                        <div>
                            <p class="cart-item__name">Produto indisponível</p>
                            <p class="field__error"><?= e($issueText['unavailable']) ?></p>
                            <form class="cart-item__actions" method="post" action="<?= e(url('/carrinho/itens/' . $item['id'] . '/remover')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="link-button">Remover<span class="visually-hidden"> item</span></button>
                            </form>
                        </div>
                    <?php else: ?>
                        <a class="cart-item__media" href="<?= e(url('/produto/' . $item['slug'])) ?>" tabindex="-1" aria-hidden="true">
                            <?php if (!empty($item['cover_path'])): ?>
                                <img src="<?= e(upload_url($item['cover_path'], 400)) ?>" alt="" width="400" height="400" loading="lazy">
                            <?php else: ?>
                                <span class="media-placeholder"><img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="32" height="32"></span>
                            <?php endif ?>
                        </a>
                        <div>
                            <a class="cart-item__name" href="<?= e(url('/produto/' . $item['slug'])) ?>"><?= e($item['name']) ?></a>
                            <?php if (!empty($item['variant_name'])): ?>
                                <p class="cart-item__variant"><?= e($item['variant_name']) ?></p>
                            <?php endif ?>
                            <?php if ($item['personalization'] !== []): ?>
                                <ul class="cart-item__pers" aria-label="Personalização">
                                    <?php foreach ($item['personalization'] as $choice): ?>
                                        <li><?= e($choice['label']) ?>: <strong><?= e($choice['display']) ?></strong></li>
                                    <?php endforeach ?>
                                </ul>
                            <?php endif ?>
                            <?php if ($item['issue'] !== null): ?>
                                <p class="field__error"><?= e($issueText[$item['issue']]) ?></p>
                            <?php endif ?>

                            <div class="cart-item__actions">
                                <?php if ($item['issue'] !== 'unavailable' && $item['issue'] !== 'out_of_stock'): ?>
                                <form method="post" action="<?= e(url('/carrinho/itens/' . $item['id'])) ?>">
                                    <?= csrf_field() ?>
                                    <div class="stepper stepper--sm" data-stepper>
                                        <label for="qtd-<?= e($item['id']) ?>" class="visually-hidden">Quantidade de <?= e($item['name']) ?></label>
                                        <button type="button" data-step="-1" aria-label="Diminuir quantidade"><?= $icon('minus', 14) ?></button>
                                        <input id="qtd-<?= e($item['id']) ?>" type="number" name="quantity" value="<?= e($item['quantity']) ?>"
                                               min="0" max="<?= e($item['max_quantity']) ?>" inputmode="numeric" data-autosubmit>
                                        <button type="button" data-step="1" aria-label="Aumentar quantidade"><?= $icon('plus', 14) ?></button>
                                    </div>
                                    <noscript><button type="submit" class="btn btn--secondary btn--sm">Atualizar</button></noscript>
                                </form>
                                <?php endif ?>
                                <form method="post" action="<?= e(url('/carrinho/itens/' . $item['id'] . '/remover')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="link-button">Remover<span class="visually-hidden"> <?= e($item['name']) ?></span></button>
                                </form>
                            </div>
                        </div>
                        <p class="cart-item__price">
                            <?php if ($item['issue'] === null): ?>
                                <strong><?= e(money($item['line_total_cents'])) ?></strong>
                            <?php else: ?>
                                <strong class="muted" aria-label="Fora do total">—</strong>
                            <?php endif ?>
                            <span><?= e(money($item['unit_price_cents'])) ?> cada</span>
                            <?php if ($item['personalization_cents'] > 0): ?>
                                <span>(<?= e(money($item['base_price_cents'])) ?> + <?= e(money($item['personalization_cents'])) ?> de personalização)</span>
                            <?php endif ?>
                        </p>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
            <li><a class="link-arrow" href="<?= e(url('/produtos')) ?>">Continuar comprando</a></li>
        </ul>

        <aside class="card summary" aria-labelledby="resumo">
            <h2 id="resumo" class="section__title">Resumo</h2>
            <div class="summary__row"><span>Subtotal (<?= e($cart['quantity']) ?> <?= $cart['quantity'] === 1 ? 'item' : 'itens' ?>)</span><span><?= e(money($cart['subtotal_cents'])) ?></span></div>
            <?php if ($cart['coupon'] !== null && $cart['coupon']['error'] === null && $cart['discount_cents'] > 0): ?>
                <div class="summary__row"><span>Cupom <?= e($cart['coupon']['code']) ?></span><span class="summary__discount">− <?= e(money($cart['discount_cents'])) ?></span></div>
            <?php endif ?>
            <div class="summary__row"><span>Frete</span><span class="muted"><?= ($cart['coupon']['free_shipping'] ?? false) && $cart['coupon']['error'] === null ? 'grátis na opção econômica' : 'calculado no checkout' ?></span></div>
            <div class="summary__row summary__row--total"><span><?= $cart['discount_cents'] > 0 ? 'Total sem frete' : 'Subtotal' ?></span><span><?= e(money($cart['total_cents'])) ?></span></div>

            <?php if ($cart['coupon'] !== null): ?>
                <div class="alert <?= $cart['coupon']['error'] !== null ? 'alert--error' : 'alert--success' ?>">
                    <div>
                        <p><strong><?= e($cart['coupon']['code']) ?></strong>
                            <?= $cart['coupon']['error'] === null ? '· ' . e($cart['coupon']['label']) : '' ?></p>
                        <?php if ($cart['coupon']['error'] !== null): ?><p><?= e($cart['coupon']['error']) ?></p><?php endif ?>
                        <form method="post" action="<?= e(url('/carrinho/cupom/remover')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="link-button">Remover cupom</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <form method="post" action="<?= e(url('/carrinho/cupom')) ?>" class="coupon-form">
                    <?= csrf_field() ?>
                    <label for="coupon-code" class="visually-hidden">Cupom de desconto</label>
                    <input id="coupon-code" name="code" maxlength="40" placeholder="Cupom de desconto" autocomplete="off" autocapitalize="characters">
                    <button type="submit" class="btn btn--secondary btn--sm">Aplicar</button>
                </form>
            <?php endif ?>
            <?php if ($cart['lead_days'] > 0): ?>
                <p class="summary__note"><?= $icon('clock', 16) ?> Produção em até <?= e($cart['lead_days']) ?> dia<?= $cart['lead_days'] > 1 ? 's' : '' ?> úte<?= $cart['lead_days'] > 1 ? 'is' : 'il' ?> + prazo de entrega.</p>
            <?php endif ?>
            <?php if ($cart['has_issues']): ?>
                <p class="alert alert--warn">Ajuste os itens destacados para continuar.</p>
                <button type="button" class="btn btn--primary btn--lg btn--block" disabled>Finalizar compra</button>
            <?php else: ?>
                <a class="btn btn--primary btn--lg btn--block" href="<?= e(url('/checkout')) ?>">Finalizar compra</a>
            <?php endif ?>
            <p class="summary__note"><?= $icon('lock', 16) ?> Frete calculado pelo CEP na próxima etapa. Compre sem precisar criar conta.</p>
        </aside>
    </div>
    <?php endif ?>

    <?php if (!empty($suggestions)): ?>
        <section class="section section--tight" aria-labelledby="sugestoes">
            <h2 id="sugestoes" class="section__title">Combina com o seu pedido</h2>
            <div class="product-grid">
                <?php foreach ($suggestions as $item): ?>
                    <?= $this->partial('partials/product-card', ['product' => $item]) ?>
                <?php endforeach ?>
            </div>
        </section>
    <?php endif ?>
</div>
