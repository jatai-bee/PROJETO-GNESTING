<?php
/**
 * @var list<array> $featured
 * @var list<array> $newest
 * @var list<array> $categories árvore (somente categorias com produtos)
 */
?>
<section class="hero">
    <div class="container">
        <span class="eyebrow">Design · Fabricação digital</span>
        <h1 class="hero__title">Objetos que <em>transformam</em> espaços.</h1>
        <p class="lead">Peças de design recortadas com precisão, acabadas à mão e pensadas para encaixar no seu dia a dia.</p>
        <div class="actions">
            <a class="btn btn--primary" href="<?= e(url('/produtos')) ?>">Ver produtos</a>
            <a class="btn btn--secondary" href="<?= e(url('/como-fazemos')) ?>">Como fazemos</a>
        </div>
    </div>
</section>

<?php if ($featured !== []): ?>
<section class="section section--tight" aria-labelledby="destaques">
    <div class="container">
        <div class="section__head">
            <h2 id="destaques" class="section__title">Destaques</h2>
            <a href="<?= e(url('/produtos')) ?>">Ver todos</a>
        </div>
        <div class="product-grid">
            <?php foreach ($featured as $product): ?>
                <?= $this->partial('partials/product-card', ['product' => $product]) ?>
            <?php endforeach ?>
        </div>
    </div>
</section>
<?php endif ?>

<?php if ($categories !== []): ?>
<section class="section section--tight" aria-labelledby="categorias">
    <div class="container">
        <h2 id="categorias" class="section__title">Explore por categoria</h2>
        <ul class="category-tiles">
            <?php foreach ($categories as $category): ?>
                <li>
                    <a class="category-tile" href="<?= e(url('/categoria/' . $category['slug'])) ?>">
                        <span class="category-tile__name"><?= e($category['name']) ?></span>
                        <span class="category-tile__count"><?= e($category['total']) ?> <?= $category['total'] === 1 ? 'produto' : 'produtos' ?></span>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
</section>
<?php endif ?>

<?php if ($newest !== []): ?>
<section class="section section--tight" aria-labelledby="novidades">
    <div class="container">
        <div class="section__head">
            <h2 id="novidades" class="section__title">Novidades</h2>
            <a href="<?= e(url('/produtos?ordem=novidades')) ?>">Ver novidades</a>
        </div>
        <div class="product-grid">
            <?php foreach ($newest as $product): ?>
                <?= $this->partial('partials/product-card', ['product' => $product]) ?>
            <?php endforeach ?>
        </div>
    </div>
</section>
<?php endif ?>

<section id="como-fazemos" class="section">
    <div class="container">
        <h2 class="section__title">Como fazemos</h2>
        <div class="pillars">
            <article class="pillar">
                <h3>Precisão</h3>
                <p>Cada peça é recortada digitalmente: encaixes exatos, bordas limpas e o mesmo padrão em todas as unidades.</p>
            </article>
            <article class="pillar">
                <h3>Acabamento</h3>
                <p>Lixamento, selagem e montagem feitos com cuidado artesanal, conferidos antes de seguir para você.</p>
            </article>
            <article class="pillar">
                <h3>Do seu jeito</h3>
                <p>Alguns objetos aceitam um toque pessoal, como um nome gravado, dentro de opções pensadas para cada peça.</p>
            </article>
        </div>
        <?php if ($featured === [] && $newest === []): ?>
            <p class="notice">Nosso catálogo está sendo preparado. Os primeiros produtos chegam em breve.</p>
        <?php endif ?>
    </div>
</section>
