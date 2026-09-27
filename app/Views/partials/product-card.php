<?php
/**
 * Cartão de produto das listagens: foto, selos, favorito, categoria, nome, prazo, preço e compra rápida.
 * @var array<string, mixed> $product     linha de CatalogRepository (CARD_FIELDS + faixa de preço das variantes)
 * @var list<int>|null       $favoriteIds favoritos do cliente (vem do layout da loja)
 */
$favoriteIds ??= [];
$soldOut = (int) $product['sellable_count'] === 0;
$priceRange = (int) $product['min_price'] !== (int) $product['max_price'];
// Preço "de" só faz sentido quando há um preço único (a variante padrão)
$compare = $priceRange || $product['compare_at_price_cents'] === null ? null : (int) $product['compare_at_price_cents'];
$offPercent = $compare !== null && $compare > (int) $product['price_cents']
    ? (int) round(100 - ((int) $product['price_cents'] * 100 / $compare)) : 0;
$readyStock = ($product['stock_mode'] ?? '') === 'stock' && (int) ($product['available'] ?? 0) > 0;
$personalizable = (int) ($product['personalization_enabled'] ?? 0) === 1;
// Compra direto do cartão só quando não há escolha a fazer (variação única, sem personalização)
$quickAdd = !$soldOut && (int) $product['variant_count'] === 1 && !$personalizable;
$isFavorite = in_array((int) $product['id'], $favoriteIds, true);
$href = url('/produto/' . $product['slug']);
$here = (string) ($_SERVER['REQUEST_URI'] ?? '/');
?>
<article class="product-card">
    <a class="product-card__media" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
        <?php if (!empty($product['cover_path'])): ?>
            <img src="<?= e(upload_url($product['cover_path'], 800)) ?>"
                 srcset="<?= e(upload_url($product['cover_path'], 400)) ?> 400w, <?= e(upload_url($product['cover_path'], 800)) ?> 800w"
                 sizes="(min-width: 1100px) 25vw, (min-width: 768px) 33vw, 50vw"
                 alt="" width="800" height="800" loading="lazy" decoding="async">
        <?php else: ?>
            <span class="media-placeholder"><img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="48" height="48"></span>
        <?php endif ?>
    </a>
    <div class="product-card__badges">
        <?php if ($soldOut): ?>
            <span class="tag tag--muted">Esgotado</span>
        <?php elseif ($offPercent > 0): ?>
            <span class="tag tag--accent">−<?= e($offPercent) ?>%</span>
        <?php endif ?>
        <?php if (!$soldOut && (int) $product['is_new'] === 1): ?>
            <span class="tag">Novo</span>
        <?php endif ?>
    </div>
    <form class="product-card__fav" method="post" action="<?= e(url('/favoritos/' . $product['id'])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="voltar" value="<?= e($here) ?>">
        <button type="submit" class="fav-button" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"
                aria-label="<?= $isFavorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>: <?= e($product['name']) ?>">
            <?= $this->partial('partials/icon', ['name' => 'heart', 'size' => 18]) ?>
        </button>
    </form>
    <div class="product-card__body">
        <span class="product-card__category"><?= e($product['category_name']) ?></span>
        <h3 class="product-card__title"><a href="<?= e($href) ?>"><?= e($product['name']) ?></a></h3>
        <?php if ($readyStock): ?>
            <span class="product-card__meta product-card__meta--ready"><?= $this->partial('partials/icon', ['name' => 'check', 'size' => 14]) ?> Pronta entrega</span>
        <?php elseif (!$soldOut): ?>
            <span class="product-card__meta"><?= $this->partial('partials/icon', ['name' => 'clock', 'size' => 14]) ?> Produzido em até <?= e($product['production_lead_days']) ?> dias úteis</span>
        <?php endif ?>
        <?php if ($personalizable && !$soldOut): ?>
            <span class="product-card__meta"><?= $this->partial('partials/icon', ['name' => 'pencil', 'size' => 14]) ?> Personalizável</span>
        <?php endif ?>
        <div class="product-card__footer">
            <p class="price">
                <?php if ($priceRange): ?><span class="price__from">a partir de</span><?php endif ?>
                <?php if ($compare !== null): ?>
                    <s class="price__old"><span class="visually-hidden">De </span><?= e(money($compare)) ?></s>
                    <span class="visually-hidden">por </span>
                <?php endif ?>
                <span class="price__current"><?= e(money((int) $product['min_price'])) ?></span>
            </p>
            <?php if ($quickAdd): ?>
                <form method="post" action="<?= e(url('/carrinho/itens')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="variant_id" value="<?= e($product['variant_id']) ?>">
                    <input type="hidden" name="quantity" value="1">
                    <button type="submit" class="btn btn--dark btn--sm">Adicionar<span class="visually-hidden"> <?= e($product['name']) ?> ao carrinho</span></button>
                </form>
            <?php elseif (!$soldOut): ?>
                <a class="btn btn--secondary btn--sm" href="<?= e($href) ?>"><?= $personalizable ? 'Personalizar' : 'Escolher opções' ?><span class="visually-hidden">: <?= e($product['name']) ?></span></a>
            <?php else: ?>
                <a class="btn btn--ghost btn--sm" href="<?= e($href) ?>">Ver detalhes<span class="visually-hidden">: <?= e($product['name']) ?></span></a>
            <?php endif ?>
        </div>
    </div>
</article>
