<?php /** @var string $heading @var list<array> $breadcrumbs */ ?>
<div class="container">
    <?= $this->partial('partials/breadcrumbs', ['breadcrumbs' => $breadcrumbs]) ?>
    <article class="prose prose--page">
        <h1><?= e($heading) ?></h1>
        <h2>Direito de arrependimento</h2>
        <p>Compras feitas pela internet podem ser canceladas em até <strong>7 dias corridos</strong> após o recebimento, conforme o art. 49 do Código de Defesa do Consumidor. O produto deve ser devolvido sem sinais de uso, na embalagem original. Devolvemos o valor pago, incluindo o frete.</p>
        <h2>Produtos personalizados</h2>
        <p>Peças com personalização (por exemplo, nome gravado) são produzidas exclusivamente para você. Mesmo assim, se houver defeito ou erro nosso na personalização, fazemos uma nova peça ou devolvemos o valor.</p>
        <h2>Defeitos</h2>
        <p>Se o produto chegar com defeito ou avaria de transporte, fale com a gente em até <strong>90 dias</strong> após o recebimento, com fotos da peça e da embalagem. Enviamos uma nova peça ou devolvemos o valor, sem custo para você.</p>
        <h2>Como solicitar</h2>
        <p>Escreva para <a href="mailto:contato@gnesting.com.br">contato@gnesting.com.br</a> com o número do pedido. Respondemos em até 2 dias úteis com as instruções de envio.</p>
    </article>
</div>
