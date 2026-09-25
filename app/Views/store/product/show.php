<?php
/**
 * @var array<string, mixed> $product
 * @var list<array{path: string, alt_text: string}> $images
 * @var list<string> $highlights
 * @var bool $inStock
 * @var int  $maxQuantity
 * @var list<array> $related
 * @var list<array> $breadcrumbs
 */
$compare = $product['compare_at_price_cents'] === null ? null : (int) $product['compare_at_price_cents'];
$price = (int) $product['price_cents'];
$leadDays = (int) $product['production_lead_days'];
$dimensions = array_filter([
    $product['width_mm'] !== null ? round($product['width_mm'] / 10, 1) : null,
    $product['height_mm'] !== null ? round($product['height_mm'] / 10, 1) : null,
    $product['depth_mm'] !== null ? round($product['depth_mm'] / 10, 1) : null,
], static fn ($v) => $v !== null);
$cm = static fn (float $v): string => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
?>
<div class="container">
    <?= $this->partial('partials/breadcrumbs', ['breadcrumbs' => $breadcrumbs]) ?>

    <article class="product">
        <div class="product__gallery gallery" data-gallery>
            <?php if ($images === []): ?>
                <div class="gallery__main"><span class="media-placeholder media-placeholder--large"><img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="96" height="96"></span></div>
            <?php else: ?>
                <figure class="gallery__main">
                    <img src="<?= e(upload_url($images[0]['path'], 1600)) ?>"
                         srcset="<?= e(upload_url($images[0]['path'], 800)) ?> 800w, <?= e(upload_url($images[0]['path'], 1600)) ?> 1600w"
                         sizes="(min-width: 1024px) 55vw, 100vw"
                         alt="<?= e($images[0]['alt_text']) ?>" width="1600" height="1600" data-gallery-main>
                </figure>
                <?php if (count($images) > 1): ?>
                <ul class="gallery__thumbs" aria-label="Mais fotos">
                    <?php foreach ($images as $index => $image): ?>
                        <li>
                            <a href="<?= e(upload_url($image['path'], 1600)) ?>" class="gallery__thumb"
                               data-gallery-thumb data-src="<?= e(upload_url($image['path'], 1600)) ?>"
                               data-srcset="<?= e(upload_url($image['path'], 800)) ?> 800w, <?= e(upload_url($image['path'], 1600)) ?> 1600w"
                               data-alt="<?= e($image['alt_text']) ?>" <?= $index === 0 ? 'aria-current="true"' : '' ?>>
                                <img src="<?= e(upload_url($image['path'], 400)) ?>" alt="Foto <?= e($index + 1) ?>: <?= e($image['alt_text']) ?>" width="400" height="400" loading="lazy">
                            </a>
                        </li>
                    <?php endforeach ?>
                </ul>
                <?php endif ?>
            <?php endif ?>
        </div>

        <div class="product__info">
            <a class="eyebrow product__category" href="<?= e(url('/categoria/' . $product['category_slug'])) ?>"><?= e($product['category_name']) ?></a>
            <h1 class="product__title"><?= e($product['name']) ?></h1>
            <?php if (!empty($product['short_description'])): ?>
                <p class="product__summary"><?= e($product['short_description']) ?></p>
            <?php endif ?>

            <p class="price price--large">
                <?php if ($compare !== null): ?>
                    <s class="price__old"><span class="visually-hidden">De </span><?= e(money($compare)) ?></s>
                    <span class="visually-hidden">por </span>
                <?php endif ?>
                <span class="price__current"><?= e(money($price)) ?></span>
                <?php if ($compare !== null): ?>
                    <span class="tag tag--accent"><?= e((int) round(($compare - $price) * 100 / $compare)) ?>% off</span>
                <?php endif ?>
            </p>

            <?php if ($inStock): ?>
                <form class="buy-box" method="post" action="<?= e(url('/carrinho/itens')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="variant_id" value="<?= e($product['variant_id']) ?>">
                    <input type="hidden" name="back" value="<?= e('/produto/' . $product['slug']) ?>">
                    <div class="buy-box__row">
                        <div class="field buy-box__qty">
                            <label for="quantity">Quantidade</label>
                            <input id="quantity" type="number" name="quantity" value="1" min="1" max="<?= e($maxQuantity) ?>" inputmode="numeric" required>
                        </div>
                        <button type="submit" class="btn btn--primary buy-box__submit">Adicionar ao carrinho</button>
                    </div>
                </form>
                <p class="stock-note">
                    <?php if ($product['stock_mode'] === 'stock'): ?>
                        <strong>Pronta entrega.</strong>
                        <?php if ((int) $product['available'] <= 5): ?>Restam <?= e($product['available']) ?> unidade<?= (int) $product['available'] > 1 ? 's' : '' ?>.<?php endif ?>
                    <?php else: ?>
                        <strong>Produzido sob encomenda:</strong> fica pronto em até <?= e($leadDays) ?> dia<?= $leadDays > 1 ? 's' : '' ?> úte<?= $leadDays > 1 ? 'is' : 'il' ?> antes do envio.
                    <?php endif ?>
                </p>
            <?php else: ?>
                <p class="alert alert--warn" role="status">Produto esgotado no momento.</p>
            <?php endif ?>

            <?php if ((int) $product['personalization_enabled'] === 1): ?>
                <p class="notice">Este objeto aceita personalização. As opções estarão disponíveis aqui em breve.</p>
            <?php endif ?>

            <?php if ($highlights !== []): ?>
                <ul class="product__highlights">
                    <?php foreach ($highlights as $highlight): ?>
                        <li><?= e($highlight) ?></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>

            <dl class="specs">
                <?php if (!empty($product['material_label'])): ?>
                    <div><dt>Material</dt><dd><?= e($product['material_label']) ?></dd></div>
                <?php endif ?>
                <?php if (!empty($product['finish_label'])): ?>
                    <div><dt>Acabamento</dt><dd><?= e($product['finish_label']) ?></dd></div>
                <?php endif ?>
                <?php if ($dimensions !== []): ?>
                    <div><dt>Medidas</dt><dd><?= e(implode(' × ', array_map($cm, $dimensions))) ?> cm <span class="muted">(L × A<?= count($dimensions) > 2 ? ' × P' : '' ?>)</span></dd></div>
                <?php endif ?>
                <?php if ($product['weight_g'] !== null): ?>
                    <div><dt>Peso</dt><dd><?= (int) $product['weight_g'] >= 1000 ? e($cm($product['weight_g'] / 1000)) . ' kg' : e($product['weight_g']) . ' g' ?></dd></div>
                <?php endif ?>
                <div><dt>Código</dt><dd class="mono"><?= e($product['sku']) ?></dd></div>
            </dl>
        </div>
    </article>

    <?php if (!empty($product['description'])): ?>
    <section class="product-description" aria-labelledby="descricao">
        <h2 id="descricao" class="section__title">Sobre o produto</h2>
        <div class="prose">
            <?php foreach (preg_split('/\R{2,}/', trim((string) $product['description'])) ?: [] as $paragraph): ?>
                <p><?= nl2br(e($paragraph), false) ?></p>
            <?php endforeach ?>
        </div>
    </section>
    <?php endif ?>

    <?php if ($related !== []): ?>
    <section class="section section--tight" aria-labelledby="relacionados">
        <h2 id="relacionados" class="section__title">Você também pode gostar</h2>
        <div class="product-grid">
            <?php foreach ($related as $item): ?>
                <?= $this->partial('partials/product-card', ['product' => $item]) ?>
            <?php endforeach ?>
        </div>
    </section>
    <?php endif ?>
</div>
