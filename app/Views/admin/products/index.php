<?php
/**
 * @var list<array<string, mixed>> $products
 * @var \GNesting\Core\Paginator $paginator
 * @var array{q:string, category_id:int, status:string} $filters
 * @var list<array<string, mixed>> $categories
 */
$params = ['q' => $filters['q'], 'categoria' => $filters['category_id'], 'status' => $filters['status']];
$currentUrl = query_url('/admin/produtos', $params + ['pagina' => $paginator->page > 1 ? $paginator->page : null]);
$currentPath = substr($currentUrl, strlen(url('/')) - 1);
?>
<div class="page-header">
    <h1 class="page-title">Produtos</h1>
    <a class="btn btn--primary" href="<?= e(url('/admin/produtos/novo')) ?>">Novo produto</a>
</div>

<form method="get" action="<?= e(url('/admin/produtos')) ?>" class="filters" role="search">
    <div class="field">
        <label for="q">Buscar</label>
        <input id="q" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Nome ou SKU">
    </div>
    <div class="field">
        <label for="categoria">Categoria</label>
        <select id="categoria" name="categoria">
            <option value="">Todas</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= e($category['id']) ?>"<?= $filters['category_id'] === $category['id'] ? ' selected' : '' ?>>
                    <?= $category['parent_id'] ? '— ' : '' ?><?= e($category['name']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="field">
        <label for="status">Situação</label>
        <select id="status" name="status">
            <option value="">Todas</option>
            <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Ativos</option>
            <option value="inactive"<?= $filters['status'] === 'inactive' ? ' selected' : '' ?>>Inativos</option>
        </select>
    </div>
    <div class="filters__actions">
        <button type="submit" class="btn btn--secondary">Filtrar</button>
        <?php if ($filters['q'] !== '' || $filters['category_id'] || $filters['status'] !== ''): ?>
            <a href="<?= e(url('/admin/produtos')) ?>">Limpar</a>
        <?php endif ?>
    </div>
</form>

<section class="panel">
    <?php if ($products === []): ?>
        <p class="muted">Nenhum produto encontrado.</p>
    <?php else: ?>
        <p class="muted table-summary"><?= e($paginator->from()) ?>–<?= e($paginator->to()) ?> de <?= e($paginator->total) ?> produto(s)</p>
        <div class="table-wrap">
            <table class="table table--stack">
                <thead>
                <tr><th><span class="visually-hidden">Imagem</span></th><th>Produto</th><th>SKU</th><th>Categoria</th><th>Preço</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr>
                </thead>
                <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td class="table__thumb">
                            <?php if ($product['cover_path']): ?>
                                <img src="<?= e(upload_url($product['cover_path'], 400)) ?>" alt="" width="48" height="48" loading="lazy">
                            <?php else: ?>
                                <span class="thumb-placeholder" title="Sem imagem">—</span>
                            <?php endif ?>
                        </td>
                        <td class="table__main">
                            <a href="<?= e(url("/admin/produtos/{$product['id']}/editar")) ?>"><?= e($product['name']) ?></a>
                            <?php if ($product['is_featured']): ?><span class="badge">Destaque</span><?php endif ?>
                            <?php if ($product['is_new']): ?><span class="badge">Lançamento</span><?php endif ?>
                        </td>
                        <td data-label="SKU"><code><?= e($product['sku']) ?></code></td>
                        <td data-label="Categoria"><?= e($product['category_name']) ?></td>
                        <td class="table__num" data-label="Preço"><?= $product['price_cents'] !== null ? e(money((int) $product['price_cents'])) : '—' ?></td>
                        <td>
                            <?= $product['is_active'] ? '<span class="status status--on">Ativo</span>' : '<span class="status status--off">Inativo</span>' ?>
                            <?php if ((int) $product['image_count'] === 0): ?><span class="status status--warn">Sem imagem</span><?php endif ?>
                        </td>
                        <td class="table__actions">
                            <form method="post" action="<?= e(url("/admin/produtos/{$product['id']}/status")) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="back" value="<?= e($currentPath) ?>">
                                <input type="hidden" name="active" value="<?= $product['is_active'] ? '0' : '1' ?>">
                                <button type="submit" class="btn btn--secondary btn--sm"><?= $product['is_active'] ? 'Desativar' : 'Ativar' ?></button>
                            </form>
                            <a class="btn btn--secondary btn--sm" href="<?= e(url("/admin/produtos/{$product['id']}/imagens")) ?>">Imagens</a>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?= $this->partial('partials/pagination', ['paginator' => $paginator, 'path' => '/admin/produtos', 'params' => $params]) ?>
    <?php endif ?>
</section>
