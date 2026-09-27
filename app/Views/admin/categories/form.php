<?php
/**
 * @var array<string, mixed>|null $category
 * @var array<int, array<string, mixed>> $parents
 * @var array $errors
 * @var array $old
 */
$f = fn (string $partial, array $vars): string => $this->partial($partial, $vars + ['errors' => $errors, 'old' => $old]);
$action = $category ? "/admin/categorias/{$category['id']}/editar" : '/admin/categorias/novo';
$parentOptions = [];
foreach ($parents as $parent) {
    $parentOptions[$parent['id']] = $parent['name'] . ($parent['is_active'] ? '' : ' (inativa)');
}
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url('/admin/categorias')) ?>">← Categorias</a>
        <h1 class="page-title"><?= $category ? e($category['name']) : 'Nova categoria' ?></h1>
    </div>
</div>

<form method="post" action="<?= e(url($action)) ?>" class="form-layout" novalidate>
    <?= csrf_field() ?>

    <section class="panel">
        <h2 class="panel__title">Dados da categoria</h2>
        <?= $f('partials/field', ['name' => 'name', 'label' => 'Nome', 'value' => $category['name'] ?? '', 'maxlength' => 100]) ?>
        <?= $f('partials/field', ['name' => 'slug', 'label' => 'Endereço (slug)', 'value' => $category['slug'] ?? '', 'required' => false,
            'hint' => $category
                ? 'Parte final da URL da categoria. Evite alterar: links já divulgados deixam de funcionar.'
                : 'Parte final da URL: /categoria/relogios. Deixe vazio para gerar a partir do nome.']) ?>
        <?= $f('partials/select', ['name' => 'parent_id', 'label' => 'Categoria principal', 'options' => $parentOptions,
            'value' => $category['parent_id'] ?? ($parentId ?? ''), 'placeholder' => '— Nenhuma (categoria principal) —', 'required' => false]) ?>
        <?= $f('partials/textarea', ['name' => 'description', 'label' => 'Descrição', 'value' => $category['description'] ?? '', 'rows' => 3, 'maxlength' => 2000]) ?>
        <div class="form-row">
            <?= $f('partials/field', ['name' => 'sort_order', 'label' => 'Ordem de exibição', 'type' => 'number', 'value' => $category['sort_order'] ?? 0,
                'required' => false, 'hint' => 'Menor aparece primeiro.']) ?>
        </div>
        <?= $f('partials/checkbox', ['name' => 'is_active', 'label' => 'Categoria ativa (visível na loja)', 'checked' => $category ? (bool) $category['is_active'] : true]) ?>
    </section>

    <section class="panel">
        <h2 class="panel__title">Buscadores (SEO)</h2>
        <?= $f('partials/field', ['name' => 'meta_title', 'label' => 'Título', 'value' => $category['meta_title'] ?? '', 'required' => false, 'maxlength' => 70, 'hint' => 'Até 70 caracteres.']) ?>
        <?= $f('partials/textarea', ['name' => 'meta_description', 'label' => 'Descrição', 'value' => $category['meta_description'] ?? '', 'rows' => 2, 'maxlength' => 160, 'hint' => 'Até 160 caracteres.']) ?>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary"><?= $category ? 'Salvar alterações' : 'Criar categoria' ?></button>
        <a class="btn btn--secondary" href="<?= e(url('/admin/categorias')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($category): ?>
    <form method="post" action="<?= e(url("/admin/categorias/{$category['id']}/excluir")) ?>" class="danger-zone"
          data-confirm="Excluir a categoria &quot;<?= e($category['name']) ?>&quot;?">
        <?= csrf_field() ?>
        <p>Excluir esta categoria. Só é possível quando ela não tem produtos nem subcategorias.</p>
        <button type="submit" class="btn btn--danger btn--sm">Excluir categoria</button>
    </form>
<?php endif ?>
