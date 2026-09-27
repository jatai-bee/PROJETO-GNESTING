<?php
/**
 * Vitrine: apresentação com colagem de produtos, garantias, categorias com foto, destaques,
 * ofertas, personalização, mais vendidos, novidades e como fazemos.
 * @var list<array> $featured
 * @var list<array> $onSale
 * @var list<array> $bestsellers
 * @var list<array> $newest
 * @var list<array> $personalizable
 * @var list<array> $categories árvore (somente categorias com produtos)
 */
$icon = fn (string $name, int $size = 24): string => $this->partial('partials/icon', ['name' => $name, 'size' => $size]);
$grid = function (array $products): string {
    $html = '';
    foreach ($products as $product) {
        $html .= $this->partial('partials/product-card', ['product' => $product]);
    }

    return $html;
};
// Colagem da apresentação: até três produtos com foto (destaques primeiro)
$collage = array_slice(array_values(array_filter(
    array_merge($featured, $newest, $bestsellers),
    static fn (array $p): bool => !empty($p['cover_path'])
)), 0, 3);
$collage = array_values(array_column($collage, null, 'id'));
?>
<section class="hero">
    <div class="container hero__grid">
        <div>
            <span class="eyebrow">Design · Fabricação digital</span>
            <h1 class="hero__title">Objetos que <em>transformam</em> espaços.</h1>
            <p class="lead">Relógios, painéis, organizadores e presentes em MDF e madeira: recortados com precisão, acabados à mão e, quando você quiser, com o seu toque.</p>
            <div class="actions">
                <a class="btn btn--primary btn--lg" href="<?= e(url('/produtos')) ?>">Ver produtos</a>
                <a class="btn btn--secondary btn--lg" href="<?= e(url('/produtos?personalizavel=1')) ?>">Personalizar um presente</a>
            </div>
            <ul class="hero__trust">
                <li>Pagamento seguro</li>
                <li>Envio para todo o Brasil</li>
                <li>Produção própria</li>
            </ul>
        </div>
        <?php if (count($collage) === 3): ?>
        <div class="hero__collage">
            <?php foreach ($collage as $item): ?>
            <a href="<?= e(url('/produto/' . $item['slug'])) ?>">
                <img src="<?= e(upload_url($item['cover_path'], 800)) ?>" alt="<?= e($item['cover_alt'] ?: $item['name']) ?>" width="800" height="800" fetchpriority="high">
                <span><?= e($item['name']) ?></span>
            </a>
            <?php endforeach ?>
        </div>
        <?php endif ?>
    </div>
</section>

<section class="section section--tight" aria-label="Por que comprar na G-Nesting">
    <div class="container">
        <ul class="trust-bar">
            <li class="trust-item"><?= $icon('truck') ?><div><strong>Entrega para todo o Brasil</strong><span>Frete calculado no carrinho</span></div></li>
            <li class="trust-item"><?= $icon('pencil') ?><div><strong>Personalize do seu jeito</strong><span>Nomes, iniciais e datas gravados</span></div></li>
            <li class="trust-item"><?= $icon('tool') ?><div><strong>Feito no nosso ateliê</strong><span>Corte a laser e acabamento à mão</span></div></li>
            <li class="trust-item"><?= $icon('shield') ?><div><strong>Compra protegida</strong><span>Pix, cartão ou boleto</span></div></li>
        </ul>
    </div>
</section>

<?php if ($categories !== []): ?>
<section class="section section--tight" aria-labelledby="categorias">
    <div class="container">
        <div class="section__head">
            <div>
                <h2 id="categorias" class="section__title">Explore por categoria</h2>
                <p class="section__subtitle">Do relógio da sala ao organizador da gaveta.</p>
            </div>
            <a class="link-arrow" href="<?= e(url('/produtos')) ?>">Todos os produtos</a>
        </div>
        <ul class="category-grid">
            <?php foreach ($categories as $n => $category): ?>
            <li<?= $n === 0 && count($categories) % 4 === 3 ? ' class="category-grid__wide"' : '' ?>>
                <a class="category-card" href="<?= e(url('/categoria/' . $category['slug'])) ?>">
                    <?php if (!empty($category['image_path'])): ?>
                        <img src="<?= e(upload_url($category['image_path'], 800)) ?>" alt="" width="800" height="800" loading="lazy">
                    <?php endif ?>
                    <span class="category-card__name"><?= e($category['name']) ?></span>
                    <span class="category-card__count"><?= e($category['total']) ?> <?= (int) $category['total'] === 1 ? 'produto' : 'produtos' ?></span>
                </a>
            </li>
            <?php endforeach ?>
        </ul>
    </div>
</section>
<?php endif ?>

<?php if ($featured !== []): ?>
<section class="section section--tight" aria-labelledby="destaques">
    <div class="container">
        <div class="section__head">
            <div>
                <h2 id="destaques" class="section__title">Destaques da semana</h2>
                <p class="section__subtitle">As peças que mais encantam quem visita o ateliê.</p>
            </div>
            <a class="link-arrow" href="<?= e(url('/produtos?destaque=1')) ?>">Ver destaques</a>
        </div>
        <div class="product-grid"><?= $grid($featured) ?></div>
    </div>
</section>
<?php endif ?>

<?php if ($onSale !== []): ?>
<section class="section section--tight" aria-labelledby="ofertas">
    <div class="container">
        <div class="section__head">
            <div>
                <h2 id="ofertas" class="section__title">Ofertas</h2>
                <p class="section__subtitle">Preços especiais por tempo limitado.</p>
            </div>
            <a class="link-arrow" href="<?= e(url('/produtos?oferta=1')) ?>">Ver todas as ofertas</a>
        </div>
        <div class="product-grid"><?= $grid($onSale) ?></div>
    </div>
</section>
<?php endif ?>

<?php if ($personalizable !== []): ?>
<section class="section section--tight" aria-labelledby="personalize">
    <div class="container">
        <div class="promo-banner">
            <div>
                <span class="eyebrow">Presente com significado</span>
                <h2 id="personalize" class="promo-banner__title">Grave um nome, uma inicial ou uma data especial.</h2>
                <p class="lead">Escolha entre as opções pensadas para cada peça: você vê o preço final antes de comprar e nós produzimos sob medida.</p>
                <div class="actions">
                    <a class="btn btn--dark" href="<?= e(url('/produtos?personalizavel=1')) ?>">Ver personalizáveis</a>
                </div>
            </div>
            <div class="promo-banner__art">
                <?php foreach ($personalizable as $item): ?>
                    <?php if (!empty($item['cover_path'])): ?>
                    <a href="<?= e(url('/produto/' . $item['slug'])) ?>"><img src="<?= e(upload_url($item['cover_path'], 400)) ?>" alt="<?= e($item['name']) ?>" width="400" height="400" loading="lazy"></a>
                    <?php endif ?>
                <?php endforeach ?>
            </div>
        </div>
    </div>
</section>
<?php endif ?>

<?php if ($bestsellers !== []): ?>
<section class="section section--tight" aria-labelledby="mais-vendidos">
    <div class="container">
        <div class="section__head">
            <h2 id="mais-vendidos" class="section__title">Mais vendidos</h2>
            <a class="link-arrow" href="<?= e(url('/produtos?ordem=mais-vendidos')) ?>">Ver ranking</a>
        </div>
        <div class="product-grid"><?= $grid($bestsellers) ?></div>
    </div>
</section>
<?php endif ?>

<?php if ($newest !== []): ?>
<section class="section section--tight" aria-labelledby="novidades">
    <div class="container">
        <div class="section__head">
            <h2 id="novidades" class="section__title">Novidades</h2>
            <a class="link-arrow" href="<?= e(url('/produtos?ordem=novidades')) ?>">Ver novidades</a>
        </div>
        <div class="product-grid"><?= $grid(array_slice($newest, 0, 4)) ?></div>
    </div>
</section>
<?php endif ?>

<section id="como-fazemos" class="section">
    <div class="container">
        <div class="section__head">
            <div>
                <h2 class="section__title">Como fazemos</h2>
                <p class="section__subtitle">Da chapa de MDF até a sua casa, em quatro etapas.</p>
            </div>
            <a class="link-arrow" href="<?= e(url('/como-fazemos')) ?>">Conheça o processo</a>
        </div>
        <ol class="how-steps">
            <li class="how-step"><h3>Projeto e corte</h3><p>Cada peça é desenhada no computador e recortada a laser ou CNC: encaixes exatos em todas as unidades.</p></li>
            <li class="how-step"><h3>Lixamento</h3><p>Bordas e faces lixadas à mão para um toque macio e sem farpas.</p></li>
            <li class="how-step"><h3>Pintura e acabamento</h3><p>Verniz, tinta ou laminado: o acabamento escolhido é aplicado e conferido.</p></li>
            <li class="how-step"><h3>Controle e envio</h3><p>Revisamos peça por peça, embalamos com proteção e enviamos com rastreio.</p></li>
        </ol>
        <?php if ($featured === [] && $newest === []): ?>
            <p class="notice">Nosso catálogo está sendo preparado. Os primeiros produtos chegam em breve.</p>
        <?php endif ?>
    </div>
</section>
