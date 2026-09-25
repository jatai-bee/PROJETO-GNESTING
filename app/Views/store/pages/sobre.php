<?php /** @var string $heading @var list<array> $breadcrumbs */ ?>
<div class="container">
    <?= $this->partial('partials/breadcrumbs', ['breadcrumbs' => $breadcrumbs]) ?>
    <article class="prose prose--page">
        <span class="eyebrow">A marca</span>
        <h1><?= e($heading) ?></h1>
        <p class="lead">A G-Nesting cria objetos de design produzidos por fabricação digital: relógios, painéis, organizadores e presentes que transformam espaços.</p>
        <p>O nome vem de <em>nesting</em>, a técnica de encaixar as peças numa chapa para aproveitar cada centímetro de material. É assim que pensamos nossos produtos: desenho preciso, pouco desperdício e nenhum detalhe sobrando.</p>
        <h2>Produtos padronizados, feitos um a um</h2>
        <p>Cada modelo tem um projeto testado e uma ficha de produção própria. Isso garante que a peça que chega até você seja igual à das fotos, com o mesmo encaixe e o mesmo acabamento.</p>
        <h2>Personalização com critério</h2>
        <p>Alguns objetos aceitam um toque pessoal, como um nome gravado. As opções são definidas para cada peça, para que o resultado final continue bonito e bem-acabado.</p>
        <p><a href="<?= e(url('/como-fazemos')) ?>">Veja como fazemos</a> ou <a href="<?= e(url('/produtos')) ?>">conheça os produtos</a>.</p>
    </article>
</div>
