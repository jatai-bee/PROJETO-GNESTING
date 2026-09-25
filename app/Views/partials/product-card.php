<?php
/**
 * Cartão de produto das listagens.
 * @var array<string, mixed> $product linha de CatalogRepository (CARD_FIELDS + faixa de preço das variantes)
 */
$soldOut = (int) $product['sellable_count'] === 0;
$priceRange = (int) $product['min_price'] !== (int) $product['max_price'];
// Preço "de" só faz sentido quando há um preço único (a variante padrão)
$compare = $priceRange || $product['compare_at_price_cents'] === null ? null : (int) $product['compare_at_price_cents'];
$href = url('/produto/' . $product['slug']);
?>
<article class="product-card">
    <a class="product-card__media" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
        <?php if (!empty($product['cover_path'])): ?>
            <img src="<?= e(upload_url($product['cover_path'], 800)) ?>"
                 srcset="<?= e(upload_url($product['cover_path'], 400)) ?> 400w, <?= e(upload_url($product['cover_path'], 800)) ?> 800w"
                 sizes="(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw"
                 alt="" width="800" height="800" loading="lazy" decoding="async">
        <?php else: ?>
            <span class="media-placeholder"><img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="48" height="48"></span>
        <?php endif ?>
        <?php if ($soldOut): ?>
            <span class="tag tag--muted">Esgotado</span>
        <?php elseif ($compare !== null): ?>
            <span class="tag tag--accent">Oferta</span>
        <?php elseif ((int) $product['is_new'] === 1): ?>
            <span class="tag">Novo</span>
        <?php endif ?>
    </a>
    <div class="product-card__body">
        <span class="product-card__category"><?= e($product['category_name']) ?></span>
        <h3 class="product-card__title"><a href="<?= e($href) ?>"><?= e($product['name']) ?></a></h3>
        <p class="price">
            <?php if ($compare !== null): ?>
                <s class="price__old"><span class="visually-hidden">De </span><?= e(money($compare)) ?></s>
                <span class="visually-hidden">por </span>
            <?php endif ?>
            <?php if ($priceRange): ?><span class="price__from">a partir de</span><?php endif ?>
            <span class="price__current"><?= e(money((int) $product['min_price'])) ?></span>
        </p>
    </div>
</article>
