<?php /** @var string $heading @var list<array> $breadcrumbs */ ?>
<div class="container">
    <?= $this->partial('partials/breadcrumbs', ['breadcrumbs' => $breadcrumbs]) ?>
    <article class="prose prose--page">
        <h1><?= e($heading) ?></h1>
        <p>Ao usar a loja G-Nesting você concorda com estas condições.</p>
        <h2>Produtos</h2>
        <p>Nossos objetos são produzidos em MDF e madeira. Por serem materiais naturais, pode haver pequenas variações de veio e tonalidade entre a peça recebida e as fotos. Medidas informadas têm tolerância de ±2 mm.</p>
        <h2>Preços</h2>
        <p>Os preços podem mudar sem aviso. Vale o preço exibido no momento da finalização do pedido. O valor do frete é informado antes do pagamento.</p>
        <h2>Personalização</h2>
        <p>Textos personalizados são reproduzidos exatamente como digitados. Confira a grafia antes de concluir o pedido. Não aceitamos conteúdo ofensivo ou que viole direitos de terceiros.</p>
        <h2>Conta</h2>
        <p>Você é responsável por manter sua senha em segredo. Podemos suspender contas usadas de forma fraudulenta.</p>
        <h2>Contato</h2>
        <p>Dúvidas: <a href="mailto:contato@gnesting.com.br">contato@gnesting.com.br</a>. Veja também a <a href="<?= e(url('/trocas-e-devolucoes')) ?>">política de trocas</a> e a <a href="<?= e(url('/privacidade')) ?>">política de privacidade</a>.</p>
    </article>
</div>
