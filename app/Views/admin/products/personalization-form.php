<?php
/**
 * @var array<string, mixed>      $product
 * @var array<string, mixed>|null $rule
 * @var array<string, string>     $types
 * @var array $errors
 * @var array $old
 */
use GNesting\Services\PersonalizationService;

$f = fn (string $partial, array $vars): string => $this->partial($partial, $vars + ['errors' => $errors, 'old' => $old]);
$r = $rule ?? [];
$base = "/admin/produtos/{$product['id']}/personalizacao";
$action = $rule ? "{$base}/{$rule['id']}/editar" : "{$base}/novo";
$valuesText = implode("\n", array_map(
    static fn (array $v): string => $v['label'] . ((int) $v['price_delta_cents'] > 0 ? ' | ' . money_input((int) $v['price_delta_cents']) : ''),
    array_filter($r['values'] ?? [], static fn (array $v): bool => (bool) $v['is_active'])
));
$charsets = array_map(static fn (array $c): string => $c[0], PersonalizationService::CHARSETS);
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url($base)) ?>">← Personalização</a>
        <h1 class="page-title"><?= e($product['name']) ?> <span class="muted">· <?= $rule ? e($rule['label']) : 'Novo campo' ?></span></h1>
    </div>
    <?= $this->partial('admin/products/tabs', ['product' => $product, 'active' => 'personalizacao'] + compact('imageCount', 'variantCount', 'ruleCount')) ?>
</div>

<form method="post" action="<?= e(url($action)) ?>" class="form-layout" novalidate>
    <?= csrf_field() ?>

    <section class="panel">
        <h2 class="panel__title">Campo</h2>
        <div class="form-grid form-grid--2">
            <?= $f('partials/field', ['name' => 'label', 'label' => 'Rótulo exibido ao cliente', 'value' => $r['label'] ?? '', 'maxlength' => 80, 'placeholder' => 'Nome gravado']) ?>
            <?= $f('partials/select', ['name' => 'type', 'label' => 'Tipo', 'value' => $r['type'] ?? 'text', 'options' => $types]) ?>
        </div>
        <?= $f('partials/field', ['name' => 'help_text', 'label' => 'Instrução', 'value' => $r['help_text'] ?? '', 'required' => false, 'maxlength' => 200,
            'placeholder' => 'Até 20 caracteres: letras, números e espaços.']) ?>
        <div class="checkbox-group">
            <?= $f('partials/checkbox', ['name' => 'is_required', 'label' => 'Obrigatório', 'checked' => (bool) ($r['is_required'] ?? false),
                'hint' => 'Sem ele preenchido, o produto não entra no carrinho.']) ?>
            <?= $f('partials/checkbox', ['name' => 'is_active', 'label' => 'Ativo na loja', 'checked' => (bool) ($r['is_active'] ?? true)]) ?>
        </div>
    </section>

    <section class="panel">
        <h2 class="panel__title">Limites</h2>
        <p class="muted">Texto usa mínimo, máximo e caracteres aceitos. Inicial usa o máximo (1 a 3 letras). Data não tem limites. Opção pré-definida usa a lista abaixo.</p>
        <div class="form-grid">
            <?= $f('partials/field', ['name' => 'min_length', 'label' => 'Mínimo de caracteres', 'value' => $r['min_length'] ?? '', 'required' => false, 'inputmode' => 'numeric']) ?>
            <?= $f('partials/field', ['name' => 'max_length', 'label' => 'Máximo de caracteres', 'value' => $r['max_length'] ?? '', 'required' => false, 'inputmode' => 'numeric']) ?>
            <?= $f('partials/select', ['name' => 'charset', 'label' => 'Caracteres aceitos', 'value' => $r['charset'] ?? 'letters_numbers', 'options' => $charsets, 'required' => false]) ?>
            <?= $f('partials/field', ['name' => 'max_size_mm', 'label' => 'Área máxima de gravação', 'value' => $r['max_size_mm'] ?? '', 'required' => false,
                'inputmode' => 'numeric', 'suffix' => 'mm', 'hint' => 'Informativo para a produção.']) ?>
        </div>
        <?= $f('partials/textarea', ['name' => 'values_text', 'label' => 'Opções (tipo "opção pré-definida")', 'value' => $valuesText, 'rows' => 5, 'required' => false,
            'hint' => 'Uma por linha. Acréscimo opcional após "|". Ex.: "Fonte clássica" ou "Fonte manuscrita | 10,00". Opções removidas são desativadas.']) ?>
    </section>

    <section class="panel">
        <h2 class="panel__title">Preço e ordem</h2>
        <div class="form-grid form-grid--2">
            <?= $f('partials/field', ['name' => 'price_delta', 'label' => 'Acréscimo por unidade', 'required' => false, 'prefix' => 'R$', 'inputmode' => 'decimal',
                'value' => money_input(isset($r['price_delta_cents']) ? (int) $r['price_delta_cents'] : null),
                'hint' => 'Cobrado quando o campo é preenchido. Em opções, soma-se ao acréscimo da opção.']) ?>
            <?= $f('partials/field', ['name' => 'sort_order', 'label' => 'Ordem', 'value' => $r['sort_order'] ?? 10, 'required' => false, 'inputmode' => 'numeric']) ?>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary"><?= $rule ? 'Salvar alterações' : 'Criar campo' ?></button>
        <a class="btn btn--secondary" href="<?= e(url($base)) ?>">Cancelar</a>
    </div>
</form>

<?php if ($rule): ?>
    <form method="post" action="<?= e(url("{$base}/{$rule['id']}/excluir")) ?>" class="danger-zone"
          data-confirm="Excluir o campo &quot;<?= e($rule['label']) ?>&quot;? Ele sai dos carrinhos; pedidos já feitos mantêm o que foi escolhido.">
        <?= csrf_field() ?>
        <p>Excluir este campo. Para só escondê-lo da loja, desmarque "Ativo na loja".</p>
        <button type="submit" class="btn btn--danger btn--sm">Excluir campo</button>
    </form>
<?php endif ?>
