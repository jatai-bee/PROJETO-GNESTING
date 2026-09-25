<?php /** @var string $heading @var list<array> $breadcrumbs */ ?>
<div class="container">
    <?= $this->partial('partials/breadcrumbs', ['breadcrumbs' => $breadcrumbs]) ?>
    <article class="prose prose--page">
        <span class="eyebrow">Processo</span>
        <h1><?= e($heading) ?></h1>
        <p class="lead">Do arquivo digital à embalagem, cada objeto passa pelas mesmas etapas, conferidas uma a uma.</p>
        <ol class="steps">
            <li><strong>Recorte CNC.</strong> A peça é recortada e gravada numa fresadora CNC a partir de chapas de MDF ou madeira, com precisão de décimos de milímetro.</li>
            <li><strong>Lixamento.</strong> Faces e bordas são lixadas à mão para tirar marcas do corte.</li>
            <li><strong>Acabamento.</strong> Aplicamos seladora, verniz ou pintura conforme o modelo, respeitando o tempo de secagem.</li>
            <li><strong>Montagem.</strong> Mecanismos, ferragens e encaixes são montados e ajustados.</li>
            <li><strong>Controle de qualidade.</strong> Conferimos medidas, acabamento e funcionamento antes de embalar.</li>
            <li><strong>Embalagem e envio.</strong> Cantos protegidos e embalagem firme para a peça chegar inteira.</li>
        </ol>
        <h2>Prazos</h2>
        <p>Produtos marcados como <strong>produzidos sob encomenda</strong> começam a ser feitos quando o pagamento é confirmado. O prazo de produção aparece na página de cada produto e é somado ao prazo de entrega.</p>
    </article>
</div>
