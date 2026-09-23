<?php
/** @var list<array<string, mixed>> $categories */
?>
<div class="page-header">
    <h1 class="page-title">Categorias</h1>
    <a class="btn btn--primary" href="<?= e(url('/admin/categorias/novo')) ?>">Nova categoria</a>
</div>

<section class="panel">
    <?php if ($categories === []): ?>
        <p class="muted">Nenhuma categoria cadastrada.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Nome</th><th>Endereço</th><th>Produtos</th><th>Ordem</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr>
                </thead>
                <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td class="<?= $category['parent_id'] ? 'table__child' : '' ?>">
                            <?php if ($category['parent_id']): ?><span aria-hidden="true">↳ </span><?php endif ?>
                            <a href="<?= e(url("/admin/categorias/{$category['id']}/editar")) ?>"><?= e($category['name']) ?></a>
                        </td>
                        <td><code>/categoria/<?= e($category['slug']) ?></code></td>
                        <td><?= e($category['product_count']) ?></td>
                        <td><?= e($category['sort_order']) ?></td>
                        <td><?= $category['is_active'] ? '<span class="status status--on">Ativa</span>' : '<span class="status status--off">Inativa</span>' ?></td>
                        <td class="table__actions">
                            <a class="btn btn--secondary btn--sm" href="<?= e(url("/admin/categorias/{$category['id']}/editar")) ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>
