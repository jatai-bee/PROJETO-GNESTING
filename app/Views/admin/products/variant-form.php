<?php
/**
 * @var array<string, mixed> $product
 * @var array<string, mixed> $variant
 * @var array $errors
 * @var array $old
 */
$f = fn (string $partial, array $vars): string => $this->partial($partial, $vars + ['errors' => $errors, 'old' => $old]);
$v = $variant;
$action = "/admin/produtos/{$product['id']}/variantes/{$variant['id']}/editar";
$dimension = fn (string $name, string $label, string $suffix): string => $f('partials/field', [
    'name' => $name, 'label' => $label, 'value' => $v[$name] ?? '', 'required' => false,
    'inputmode' => 'numeric', 'suffix' => $suffix,
]);
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url("/admin/produtos/{$product['id']}/variantes")) ?>">← Variações</a>
        <h1 class="page-title"><?= e($product['name']) ?> <span class="muted">· <?= e($variant['name'] ?? $variant['sku']) ?></span></h1>
    </div>
    <?= $this->partial('admin/products/tabs', ['product' => $product, 'active' => 'variantes'] + compact('imageCount', 'variantCount', 'ruleCount')) ?>
</div>

<form method="post" action="<?= e(url($action)) ?>" class="form-layout" novalidate>
    <?= csrf_field() ?>

    <section class="panel">
        <h2 class="panel__title">Preço e identificação</h2>
        <div class="form-grid">
            <?= $f('partials/field', ['name' => 'sku', 'label' => 'SKU', 'value' => $v['sku'], 'maxlength' => 40]) ?>
            <?= $f('partials/field', ['name' => 'price', 'label' => 'Preço de venda', 'value' => money_input((int) $v['price_cents']),
                'inputmode' => 'decimal', 'prefix' => 'R$']) ?>
            <?= $f('partials/field', ['name' => 'compare_at_price', 'label' => 'Preço "de" (promoção)', 'required' => false,
                'value' => money_input($v['compare_at_price_cents'] === null ? null : (int) $v['compare_at_price_cents']),
                'inputmode' => 'decimal', 'prefix' => 'R$']) ?>
        </div>
        <?= $f('partials/checkbox', ['name' => 'is_active', 'label' => 'Ativa (à venda na loja)', 'checked' => (bool) $v['is_active']]) ?>
        <?php if (!empty($errors['is_active'])): ?><p class="field__error"><?= e($errors['is_active']) ?></p><?php endif ?>
    </section>

    <section class="panel">
        <h2 class="panel__title">Material e medidas</h2>
        <div class="form-grid">
            <?= $f('partials/field', ['name' => 'material_label', 'label' => 'Material', 'value' => $v['material_label'] ?? '', 'required' => false]) ?>
            <?= $f('partials/field', ['name' => 'finish_label', 'label' => 'Acabamento', 'value' => $v['finish_label'] ?? '', 'required' => false]) ?>
            <?= $dimension('width_mm', 'Largura', 'mm') ?>
            <?= $dimension('height_mm', 'Altura', 'mm') ?>
            <?= $dimension('depth_mm', 'Profundidade', 'mm') ?>
            <?= $dimension('weight_g', 'Peso', 'g') ?>
        </div>
        <h3 class="panel__subtitle">Embalagem</h3>
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
            <?= $f('partials/select', ['name' => 'stock_mode', 'label' => 'Modo', 'value' => $v['stock_mode'], 'options' => [
                'made_to_order' => 'Produzido sob pedido',
                'stock' => 'Pronta entrega (controla quantidade)',
            ]]) ?>
            <?= $f('partials/field', ['name' => 'quantity_on_hand', 'label' => 'Quantidade em estoque', 'value' => $v['quantity_on_hand'],
                'required' => false, 'inputmode' => 'numeric', 'suffix' => 'un.']) ?>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary">Salvar variação</button>
        <a class="btn btn--secondary" href="<?= e(url("/admin/produtos/{$product['id']}/variantes")) ?>">Cancelar</a>
    </div>
</form>
