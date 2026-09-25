<?php
/**
 * @var array<string, string> $settings
 * @var array $errors
 * @var array $old
 */
use GNesting\Helpers\BrazilianDocument;

$f = fn (string $partial, array $vars): string => $this->partial($partial, $vars + ['errors' => $errors, 'old' => $old]);
?>
<div class="page-header">
    <h1 class="page-title">Configurações</h1>
</div>

<form method="post" action="<?= e(url('/admin/configuracoes')) ?>" class="form-layout" novalidate>
    <?= csrf_field() ?>

    <section class="panel">
        <h2 class="panel__title">WhatsApp</h2>
        <p class="muted panel__intro">Aparece como botão na loja, na página de cada produto (com o nome do produto) e na página do pedido (com o número).
            O WhatsApp complementa o atendimento: pedidos e pagamentos continuam pela loja.</p>
        <div class="form-grid form-grid--2">
            <?= $f('partials/field', ['name' => 'whatsapp_number', 'label' => 'Número com DDD', 'required' => false, 'inputmode' => 'tel',
                'value' => BrazilianDocument::formatPhone($settings['whatsapp.number'] ?: null), 'placeholder' => '(71) 99999-8888',
                'hint' => 'Vazio = sem botões de WhatsApp na loja.']) ?>
            <?= $f('partials/field', ['name' => 'whatsapp_default_message', 'label' => 'Mensagem inicial', 'required' => false, 'maxlength' => 300,
                'value' => $settings['whatsapp.default_message']]) ?>
        </div>
        <?= $f('partials/checkbox', ['name' => 'whatsapp_floating_button', 'label' => 'Mostrar botão flutuante em todas as páginas da loja',
            'checked' => $settings['whatsapp.floating_button'] === '1']) ?>
    </section>

    <section class="panel">
        <h2 class="panel__title">Loja</h2>
        <div class="form-grid form-grid--2">
            <?= $f('partials/field', ['name' => 'store_contact_email', 'label' => 'E-mail de contato', 'type' => 'email', 'required' => false,
                'value' => $settings['store.contact_email'], 'hint' => 'Exibido no rodapé e nas páginas institucionais.']) ?>
            <?= $f('partials/field', ['name' => 'store_announcement', 'label' => 'Faixa de avisos', 'required' => false, 'maxlength' => 140,
                'value' => $settings['store.announcement'], 'placeholder' => 'Ex.: Frete grátis acima de R$ 300 · Use o cupom BEMVINDO',
                'hint' => 'Uma linha no topo da loja. Vazio = sem faixa.']) ?>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary">Salvar</button>
    </div>
</form>
