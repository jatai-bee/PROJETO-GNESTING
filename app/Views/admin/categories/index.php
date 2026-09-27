<?php
/**
 * Categorias em árvore: cada categoria principal em um bloco, com as subcategorias dentro.
 * @var list<array<string, mixed>> $categories principais seguidas das suas filhas (CategoryRepository::allWithCounts)
 */
$mains = [];
$children = [];
foreach ($categories as $category) {
    if ($category['parent_id'] === null) {
        $mains[] = $category;
    } else {
        $children[(int) $category['parent_id']][] = $category;
    }
}
$status = static fn (array $c): string => $c['is_active']
    ? '<span class="status status--on">Ativa</span>'
    : '<span class="status status--off">Inativa</span>';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Categorias</h1>
        <p class="muted"><?= e(count($mains)) ?> principais e <?= e(count($categories) - count($mains)) ?> subcategorias. A ordem daqui é a do menu da loja.</p>
    </div>
    <a class="btn btn--primary" href="<?= e(url('/admin/categorias/novo')) ?>">Nova categoria</a>
</div>

<?php if ($categories === []): ?>
    <div class="empty-state">
        <p>Nenhuma categoria cadastrada. Comece pelas principais (ex.: Relógios, Decoração) e depois crie as subcategorias.</p>
        <a class="btn btn--primary" href="<?= e(url('/admin/categorias/novo')) ?>">Criar a primeira categoria</a>
    </div>
<?php else: ?>
    <div class="category-tree">
        <?php foreach ($mains as $main): ?>
            <?php
            $subs = $children[(int) $main['id']] ?? [];
            $total = (int) $main['product_count'] + array_sum(array_map(static fn (array $c): int => (int) $c['product_count'], $subs));
            ?>
            <section class="panel category-node<?= $main['is_active'] ? '' : ' category-node--off' ?>" aria-labelledby="cat-<?= e($main['id']) ?>">
                <header class="category-node__head">
                    <span class="category-node__thumb">
                        <?php if (!empty($main['image_path'])): ?><img src="<?= e(upload_url($main['image_path'], 400)) ?>" alt="" width="56" height="56"><?php endif ?>
                    </span>
                    <div class="category-node__title">
                        <h2 id="cat-<?= e($main['id']) ?>"><a href="<?= e(url("/admin/categorias/{$main['id']}/editar")) ?>"><?= e($main['name']) ?></a></h2>
                        <p class="muted"><code>/categoria/<?= e($main['slug']) ?></code> · <?= e($total) ?> <?= $total === 1 ? 'produto' : 'produtos' ?> · ordem <?= e($main['sort_order']) ?></p>
                    </div>
                    <?= $status($main) ?>
                    <div class="category-node__actions">
                        <a class="btn btn--ghost btn--sm" href="<?= e(url('/admin/categorias/novo?mae=' . $main['id'])) ?>">+ Subcategoria</a>
                        <a class="btn btn--secondary btn--sm" href="<?= e(url("/admin/categorias/{$main['id']}/editar")) ?>">Editar</a>
                    </div>
                </header>
                <?php if ($subs !== []): ?>
                    <ul class="category-node__children">
                        <?php foreach ($subs as $sub): ?>
                            <li>
                                <a href="<?= e(url("/admin/categorias/{$sub['id']}/editar")) ?>"><?= e($sub['name']) ?></a>
                                <span class="muted"><?= e($sub['product_count']) ?> <?= (int) $sub['product_count'] === 1 ? 'produto' : 'produtos' ?></span>
                                <?= $status($sub) ?>
                                <a class="category-node__products" href="<?= e(url('/admin/produtos?categoria=' . $sub['id'])) ?>">ver produtos</a>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php else: ?>
                    <p class="muted category-node__empty">Sem subcategorias.</p>
                <?php endif ?>
            </section>
        <?php endforeach ?>
    </div>
<?php endif ?>
