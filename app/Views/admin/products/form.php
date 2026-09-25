<?php
/**
 * @var array<string, mixed>|null $product
 * @var list<array<string, mixed>> $categories
 * @var int $imageCount
 * @var array $errors
 * @var array $old
 */
$f = fn (string $partial, array $vars): string => $this->partial($partial, $vars + ['errors' => $errors, 'old' => $old]);
$p = $product ?? [];
$action = $product ? "/admin/produtos/{$product['id']}/editar" : '/admin/produtos/novo';

$categoryOptions = [];
foreach ($categories as $category) {
    $categoryOptions[$category['id']] = ($category['parent_id'] ? '— ' : '') . $category['name'] . ($category['is_active'] ? '' : ' (inativa)');
}

$dimension = fn (string $name, string $label, string $suffix): string => $f('partials/field', [
    'name' => $name, 'label' => $label, 'value' => $p[$name] ?? '', 'required' => false,
    'inputmode' => 'numeric', 'suffix' => $suffix,
]);
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url('/admin/produtos')) ?>">← Produtos</a>
        <h1 class="page-title"><?= $product ? e($product['name']) : 'Novo produto' ?></h1>
    </div>
    <?php if ($product): ?>
        <?= $this->partial('admin/products/tabs', ['product' => $product, 'active' => 'dados', 'imageCount' => $imageCount]) ?>
    <?php endif ?>
</div>

<?php if ($product): ?>
    <section class="status-bar status-bar--<?= $product['is_active'] ? 'on' : 'off' ?>">
        <div>
            <strong><?= $product['is_active'] ? 'Ativo: visível na loja' : 'Inativo: não aparece na loja' ?></strong>
            <?php if (!$product['is_active'] && $imageCount === 0): ?>
                <span>Para ativar, <a href="<?= e(url("/admin/produtos/{$product['id']}/imagens")) ?>">envie pelo menos uma imagem</a>.</span>
            <?php endif ?>
        </div>
        <form method="post" action="<?= e(url("/admin/produtos/{$product['id']}/status")) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="active" value="<?= $product['is_active'] ? '0' : '1' ?>">
            <button type="submit" class="btn btn--<?= $product['is_active'] ? 'secondary' : 'primary' ?> btn--sm"><?= $product['is_active'] ? 'Desativar' : 'Ativar produto' ?></button>
        </form>
    </section>
<?php endif ?>

<form method="post" action="<?= e(url($action)) ?>" class="form-layout" novalidate>
    <?= csrf_field() ?>

    <section class="panel">
        <h2 class="panel__title">Apresentação</h2>
        <?= $f('partials/field', ['name' => 'name', 'label' => 'Nome do produto', 'value' => $p['name'] ?? '', 'maxlength' => 150]) ?>
        <?= $f('partials/select', ['name' => 'category_id', 'label' => 'Categoria', 'options' => $categoryOptions, 'value' => $p['category_id'] ?? '', 'placeholder' => 'Selecione...']) ?>
        <?= $f('partials/field', ['name' => 'slug', 'label' => 'Endereço (slug)', 'value' => $p['slug'] ?? '', 'required' => false,
            'hint' => $product
                ? 'Parte final da URL do produto. Evite alterar: links já divulgados deixam de funcionar.'
                : 'Parte final da URL: /produto/relogio-geometrico. Deixe vazio para gerar a partir do nome.']) ?>
        <?= $f('partials/textarea', ['name' => 'short_description', 'label' => 'Resumo', 'value' => $p['short_description'] ?? '', 'rows' => 2, 'maxlength' => 300,
            'hint' => 'Uma frase que aparece na vitrine. Até 300 caracteres.']) ?>
        <?= $f('partials/textarea', ['name' => 'description', 'label' => 'Descrição', 'value' => $p['description'] ?? '', 'rows' => 6, 'maxlength' => 10000]) ?>
        <?= $f('partials/textarea', ['name' => 'highlights', 'label' => 'Características', 'value' => $p['highlights'] ?? '', 'rows' => 4, 'maxlength' => 2000,
            'hint' => 'Uma por linha. Ex.: "Máquina de ponteiro silenciosa".']) ?>
        <div class="checkbox-group">
            <?= $f('partials/checkbox', ['name' => 'is_featured', 'label' => 'Destaque na página inicial', 'checked' => (bool) ($p['is_featured'] ?? false)]) ?>
            <?= $f('partials/checkbox', ['name' => 'is_new', 'label' => 'Lançamento', 'checked' => (bool) ($p['is_new'] ?? false)]) ?>
        </div>
    </section>

    <section class="panel">
        <h2 class="panel__title">Preço e identificação</h2>
        <?php if ($product): ?>
            <p class="muted">SKU, preço, material, medidas e estoque desta página são os da <strong>variação padrão</strong>. As demais ficam na aba Variações.</p>
        <?php endif ?>
        <div class="form-grid">
            <?= $f('partials/field', ['name' => 'sku', 'label' => 'SKU', 'value' => $p['sku'] ?? '', 'maxlength' => 40, 'placeholder' => 'REL-GEO-001',
                'hint' => 'Código único e permanente do produto.']) ?>
            <?= $f('partials/field', ['name' => 'price', 'label' => 'Preço de venda', 'value' => money_input(isset($p['price_cents']) ? (int) $p['price_cents'] : null),
                'inputmode' => 'decimal', 'placeholder' => '129,90', 'prefix' => 'R$']) ?>
            <?= $f('partials/field', ['name' => 'compare_at_price', 'label' => 'Preço "de" (promoção)', 'required' => false,
                'value' => money_input(isset($p['compare_at_price_cents']) ? (int) $p['compare_at_price_cents'] : null),
                'inputmode' => 'decimal', 'prefix' => 'R$', 'hint' => 'Exibido riscado. Deve ser maior que o preço de venda.']) ?>
            <?= $f('partials/field', ['name' => 'production_lead_days', 'label' => 'Prazo de produção', 'value' => $p['production_lead_days'] ?? 3,
                'inputmode' => 'numeric', 'suffix' => 'dias úteis', 'hint' => 'Informado ao cliente, antes do prazo de envio.']) ?>
        </div>
    </section>

    <section class="panel">
        <h2 class="panel__title">Material e medidas <small class="muted">(exibidos na loja)</small></h2>
        <div class="form-grid">
            <?= $f('partials/field', ['name' => 'material_label', 'label' => 'Material', 'value' => $p['material_label'] ?? '', 'required' => false, 'placeholder' => 'MDF amadeirado 6 mm']) ?>
            <?= $f('partials/field', ['name' => 'finish_label', 'label' => 'Acabamento', 'value' => $p['finish_label'] ?? '', 'required' => false, 'placeholder' => 'Natural']) ?>
            <?= $dimension('width_mm', 'Largura', 'mm') ?>
            <?= $dimension('height_mm', 'Altura', 'mm') ?>
            <?= $dimension('depth_mm', 'Profundidade', 'mm') ?>
            <?= $dimension('weight_g', 'Peso', 'g') ?>
        </div>
        <h3 class="panel__subtitle">Embalagem <small class="muted">(usada no cálculo de frete)</small></h3>
        <div class="form-grid">
            <?= $dimension('package_width_mm', 'Largura', 'mm') ?>
            <?= $dimension('package_height_mm', 'Altura', 'mm') ?>
            <?= $dimension('package_length_mm', 'Comprimento', 'mm') ?>
            <?= $dimension('package_weight_g', 'Peso total', 'g') ?>
        </div>
    </section>

    <section class="panel">
        <h2 class="panel__title">Estoque</h2>
        <div class="form-grid form-grid--2">
            <?= $f('partials/select', ['name' => 'stock_mode', 'label' => 'Modo', 'value' => $p['stock_mode'] ?? 'made_to_order', 'options' => [
                'made_to_order' => 'Produzido sob pedido',
                'stock' => 'Pronta entrega (controla quantidade)',
            ]]) ?>
            <?= $f('partials/field', ['name' => 'quantity_on_hand', 'label' => 'Quantidade em estoque', 'value' => $p['quantity_on_hand'] ?? 0,
                'required' => false, 'inputmode' => 'numeric', 'suffix' => 'un.', 'hint' => 'Usada apenas em "Pronta entrega". Alterações ficam registradas.']) ?>
        </div>
        <?php if (!empty($p['quantity_reserved'])): ?>
            <p class="muted">Reservado em pedidos: <?= e($p['quantity_reserved']) ?> un.</p>
        <?php endif ?>
    </section>

    <section class="panel">
        <h2 class="panel__title">Buscadores (SEO)</h2>
        <?= $f('partials/field', ['name' => 'meta_title', 'label' => 'Título', 'value' => $p['meta_title'] ?? '', 'required' => false, 'maxlength' => 70, 'hint' => 'Até 70 caracteres. Vazio = nome do produto.']) ?>
        <?= $f('partials/textarea', ['name' => 'meta_description', 'label' => 'Descrição', 'value' => $p['meta_description'] ?? '', 'rows' => 2, 'maxlength' => 160, 'hint' => 'Até 160 caracteres. Vazio = resumo.']) ?>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary"><?= $product ? 'Salvar alterações' : 'Criar produto' ?></button>
        <a class="btn btn--secondary" href="<?= e(url('/admin/produtos')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($product): ?>
    <form method="post" action="<?= e(url("/admin/produtos/{$product['id']}/excluir")) ?>" class="danger-zone"
          data-confirm="Excluir o produto &quot;<?= e($product['name']) ?>&quot;? Ele sai da loja; o histórico de pedidos é preservado.">
        <?= csrf_field() ?>
        <p>Excluir este produto. Ele deixa de aparecer na loja e no painel; o SKU continua reservado.</p>
        <button type="submit" class="btn btn--danger btn--sm">Excluir produto</button>
    </form>
<?php endif ?>
