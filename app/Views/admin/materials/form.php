<?php
/**
 * @var array<string, mixed>|null $material
 * @var array<string, string> $units
 * @var array $errors
 * @var array $old
 */
$f = fn (string $partial, array $vars): string => $this->partial($partial, $vars + ['errors' => $errors, 'old' => $old]);
$m = $material ?? [];
$action = $material ? "/admin/materiais/{$material['id']}/editar" : '/admin/materiais/novo';
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url('/admin/materiais')) ?>">← Materiais</a>
        <h1 class="page-title"><?= $material ? e($material['name'] . ' ' . format_decimal($material['thickness_mm']) . ' mm') : 'Novo material' ?></h1>
    </div>
</div>

<form method="post" action="<?= e(url($action)) ?>" class="form-layout" novalidate>
    <?= csrf_field() ?>
    <section class="panel">
        <h2 class="panel__title">Identificação</h2>
        <div class="form-grid">
            <?= $f('partials/field', ['name' => 'code', 'label' => 'Código', 'value' => $m['code'] ?? '', 'maxlength' => 40, 'placeholder' => 'MDF-AMD-06']) ?>
            <?= $f('partials/field', ['name' => 'name', 'label' => 'Nome', 'value' => $m['name'] ?? '', 'maxlength' => 100, 'placeholder' => 'MDF amadeirado']) ?>
            <?= $f('partials/field', ['name' => 'thickness_mm', 'label' => 'Espessura', 'value' => format_decimal($m['thickness_mm'] ?? null), 'inputmode' => 'decimal', 'suffix' => 'mm']) ?>
            <?= $f('partials/select', ['name' => 'unit', 'label' => 'Unidade', 'value' => $m['unit'] ?? 'sheet', 'options' => $units]) ?>
        </div>
        <?= $f('partials/checkbox', ['name' => 'is_active', 'label' => 'Ativo (disponível para novas fichas)', 'checked' => (bool) ($m['is_active'] ?? true)]) ?>
    </section>

    <section class="panel">
        <h2 class="panel__title">Chapa, custo e saldo</h2>
        <div class="form-grid">
            <?= $f('partials/field', ['name' => 'sheet_width_mm', 'label' => 'Largura da chapa', 'value' => $m['sheet_width_mm'] ?? '', 'required' => false, 'inputmode' => 'numeric', 'suffix' => 'mm']) ?>
            <?= $f('partials/field', ['name' => 'sheet_length_mm', 'label' => 'Comprimento da chapa', 'value' => $m['sheet_length_mm'] ?? '', 'required' => false, 'inputmode' => 'numeric', 'suffix' => 'mm']) ?>
            <?= $f('partials/field', ['name' => 'cost', 'label' => 'Custo por unidade', 'required' => false, 'prefix' => 'R$', 'inputmode' => 'decimal',
                'value' => money_input(isset($m['cost_cents']) ? (int) $m['cost_cents'] : null), 'hint' => 'Usado para estimar o custo de material por peça.']) ?>
            <?= $f('partials/field', ['name' => 'stock_qty', 'label' => 'Saldo em estoque', 'value' => format_decimal($m['stock_qty'] ?? '0'), 'required' => false, 'inputmode' => 'decimal']) ?>
            <?= $f('partials/field', ['name' => 'reorder_level', 'label' => 'Estoque mínimo', 'value' => format_decimal($m['reorder_level'] ?? null), 'required' => false,
                'inputmode' => 'decimal', 'hint' => 'Com o saldo neste valor ou abaixo, a lista mostra "Repor".']) ?>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary"><?= $material ? 'Salvar alterações' : 'Cadastrar material' ?></button>
        <a class="btn btn--secondary" href="<?= e(url('/admin/materiais')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($material): ?>
    <form method="post" action="<?= e(url("/admin/materiais/{$material['id']}/excluir")) ?>" class="danger-zone"
          data-confirm="Excluir o material &quot;<?= e($material['name']) ?>&quot;?">
        <?= csrf_field() ?>
        <p>Excluir este material. Só é possível se nenhuma ficha o usar; caso contrário, desative-o.</p>
        <button type="submit" class="btn btn--danger btn--sm">Excluir material</button>
    </form>
<?php endif ?>
