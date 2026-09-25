<?php /** @var string $heading @var list<array> $breadcrumbs */ ?>
<div class="container">
    <?= $this->partial('partials/breadcrumbs', ['breadcrumbs' => $breadcrumbs]) ?>
    <article class="prose prose--page">
        <h1><?= e($heading) ?></h1>
        <p>Esta política explica como a G-Nesting trata dados pessoais, conforme a Lei Geral de Proteção de Dados (Lei nº 13.709/2018).</p>
        <h2>Dados que coletamos</h2>
        <ul>
            <li><strong>Cadastro:</strong> nome, e-mail e senha (guardada de forma criptografada, nunca em texto).</li>
            <li><strong>Pedidos:</strong> endereço de entrega, telefone, CPF (para nota fiscal e transporte) e itens comprados.</li>
            <li><strong>Navegação:</strong> cookies essenciais para manter sua sessão e seu carrinho.</li>
        </ul>
        <h2>Pagamentos</h2>
        <p>Dados de cartão são digitados no ambiente do provedor de pagamento e <strong>não passam</strong> pelos nossos servidores.</p>
        <h2>Para que usamos</h2>
        <p>Para processar pedidos, emitir nota fiscal, entregar os produtos, prestar atendimento e cumprir obrigações legais. Não vendemos seus dados.</p>
        <h2>Cookies</h2>
        <p>Usamos apenas cookies necessários ao funcionamento da loja: sessão (login e segurança) e carrinho (guarda seus itens por até 30 dias).</p>
        <h2>Seus direitos</h2>
        <p>Você pode pedir acesso, correção, uma cópia dos seus dados (em arquivo) ou a exclusão do seu cadastro escrevendo, do e-mail cadastrado, para <a href="mailto:contato@gnesting.com.br">contato@gnesting.com.br</a>. Respondemos em até 15 dias.</p>
        <p>Na exclusão, apagamos conta, endereços e contatos. Os dados dos pedidos dos últimos <?= (int) config('security.privacy.order_retention_years', 5) ?> anos são mantidos, porque a legislação fiscal e o Código de Defesa do Consumidor exigem; pedidos mais antigos são anonimizados junto com o cadastro.</p>
    </article>
</div>
