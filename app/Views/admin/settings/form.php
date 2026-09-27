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

<section class="panel">
    <h2 class="panel__title">Configuração em arquivo (YAML)</h2>
    <p class="muted panel__intro">Um arquivo de texto com as configurações acima, a <strong>tabela de frete</strong>, as <strong>categorias</strong> e os
        <strong>materiais</strong>. Serve para guardar uma cópia, levar a configuração do computador para a hospedagem e ajustar o frete
        num editor de texto. Produtos, pedidos e clientes não entram: estão no backup do banco (Sistema).</p>
    <p><a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/configuracoes/exportar')) ?>">Exportar configuração (.yaml)</a></p>

    <form method="post" action="<?= e(url('/admin/configuracoes/importar')) ?>" enctype="multipart/form-data" class="upload-form"
          data-confirm="Importar este arquivo? Os valores dele substituem os atuais (nada é apagado).">
        <?= csrf_field() ?>
        <div class="field">
            <label for="arquivo-config">Importar configuração</label>
            <input id="arquivo-config" type="file" name="arquivo" accept=".yaml,.yml" required>
            <small class="field__hint">Importar nunca apaga: categorias são encontradas pelo slug e materiais pelo código, e o que não estiver no arquivo fica
                como está. O arquivo é conferido inteiro antes; se houver erro, nada é gravado e a mensagem diz onde. O saldo dos materiais não muda.</small>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--secondary btn--sm">Importar</button></div>
    </form>
</section>
