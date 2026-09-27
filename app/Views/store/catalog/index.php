<?php
/**
 * Listagem: todos os produtos, categoria ou busca.
 * Filtros à esquerda (gaveta no celular), filtros ativos como etiquetas removíveis e ordenação na barra.
 * @var string      $heading
 * @var string|null $intro
 * @var list<array> $products
 * @var \GNesting\Core\Paginator|null $paginator null = busca sem termo
 * @var array       $filters   normalizados (q, terms, min_cents, max_cents, sort, flags)
 * @var array       $params    filtros atuais para links (q, min, max, ordem, oferta, destaque, pronta, personalizavel)
 * @var string      $path
 * @var list<array> $categoryTree
 * @var array|null  $activeCategory
 * @var bool        $isSearch
 * @var bool        $hasFilters
 * @var list<array> $breadcrumbs
 */
$sortLabels = [
    'relevancia' => $isSearch ? 'Mais relevantes' : 'Em destaque',
    'novidades' => 'Novidades',
    'menor-preco' => 'Menor preço',
    'maior-preco' => 'Maior preço',
    'mais-vendidos' => 'Mais vendidos',
];
$flagLabels = [
    'oferta' => 'Em oferta',
    'pronta' => 'Pronta entrega',
    'personalizavel' => 'Personalizável',
    'destaque' => 'Destaques',
];
$activeId = $activeCategory['id'] ?? null;
$activeParentId = $activeCategory['parent_id'] ?? null;

// Etiquetas dos filtros aplicados: cada uma leva à mesma página sem aquele filtro
$chips = [];
foreach ($flagLabels as $flag => $label) {
    if (($params[$flag] ?? null) !== null) {
        $chips[] = ['label' => $label, 'url' => query_url($path, [$flag => null] + $params)];
    }
}
if ($params['min'] !== null || $params['max'] !== null) {
    $range = match (true) {
        $params['min'] !== null && $params['max'] !== null => 'R$ ' . $params['min'] . ' a R$ ' . $params['max'],
        $params['min'] !== null => 'A partir de R$ ' . $params['min'],
        default => 'Até R$ ' . $params['max'],
    };
    $chips[] = ['label' => $range, 'url' => query_url($path, ['min' => null, 'max' => null] + $params)];
}
?>
<div class="container">
    <?= $this->partial('partials/breadcrumbs', ['breadcrumbs' => $breadcrumbs]) ?>

    <header class="page-head">
        <h1 class="page-head__title"><?= e($heading) ?></h1>
        <?php if (!empty($intro)): ?>
            <p class="page-head__intro"><?= e($intro) ?></p>
        <?php endif ?>
        <?php if (!empty($activeCategory['children'])): ?>
            <ul class="chips" aria-label="Subcategorias">
                <li><a class="chip chip--active" href="<?= e(url('/categoria/' . $activeCategory['slug'])) ?>" aria-current="page">Tudo</a></li>
                <?php foreach ($activeCategory['children'] as $child): ?>
                    <?php if ((int) $child['product_count'] > 0): ?>
                    <li><a class="chip" href="<?= e(url('/categoria/' . $child['slug'])) ?>"><?= e($child['name']) ?> <small><?= e($child['product_count']) ?></small></a></li>
                    <?php endif ?>
                <?php endforeach ?>
            </ul>
        <?php elseif (!empty($activeCategory['parent'])): ?>
            <ul class="chips" aria-label="Outras opções em <?= e($activeCategory['parent']['name']) ?>">
                <li><a class="chip" href="<?= e(url('/categoria/' . $activeCategory['parent']['slug'])) ?>">← Tudo em <?= e($activeCategory['parent']['name']) ?></a></li>
            </ul>
        <?php endif ?>
    </header>

    <?php if ($isSearch && $paginator === null): ?>
        <form class="search-page" method="get" action="<?= e(url('/busca')) ?>" role="search">
            <label for="busca-pagina">O que você procura?</label>
            <div class="search-page__row">
                <input id="busca-pagina" type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="80" placeholder="Ex.: relógio, painel, organizador">
                <button type="submit" class="btn btn--primary">Buscar</button>
            </div>
            <?php if ($filters['q'] !== ''): ?>
                <p class="field__hint">Digite pelo menos 2 letras.</p>
            <?php endif ?>
        </form>
    <?php else: ?>
    <div class="catalog">
        <aside class="catalog__filters" aria-label="Filtros">
            <details class="filters" <?= $hasFilters ? 'open' : '' ?>>
                <summary class="filters__toggle">
                    <span><?= $this->partial('partials/icon', ['name' => 'filter', 'size' => 18]) ?> Filtrar<?= $chips !== [] ? ' (' . count($chips) . ')' : '' ?></span>
                </summary>
                <div class="filters__body">
                    <form method="get" action="<?= e(url($path)) ?>" class="filters__form">
                        <?php if ($isSearch): ?>
                            <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
                        <?php endif ?>
                        <?php if ($params['ordem'] !== null): ?>
                            <input type="hidden" name="ordem" value="<?= e($params['ordem']) ?>">
                        <?php endif ?>

                        <fieldset class="filter-group toggle-list">
                            <legend class="filter-group__title">Mostrar</legend>
                            <?php foreach ($flagLabels as $flag => $label): ?>
                                <label><input type="checkbox" name="<?= e($flag) ?>" value="1" <?= ($params[$flag] ?? null) !== null ? 'checked' : '' ?> data-autosubmit> <?= e($label) ?></label>
                            <?php endforeach ?>
                        </fieldset>

                        <fieldset class="filter-group">
                            <legend class="filter-group__title">Preço</legend>
                            <div class="price-range">
                                <div class="field">
                                    <label for="min" class="visually-hidden">Preço mínimo</label>
                                    <input id="min" name="min" inputmode="decimal" value="<?= e($params['min'] ?? '') ?>" placeholder="Mín. R$">
                                </div>
                                <div class="field">
                                    <label for="max" class="visually-hidden">Preço máximo</label>
                                    <input id="max" name="max" inputmode="decimal" value="<?= e($params['max'] ?? '') ?>" placeholder="Máx. R$">
                                </div>
                            </div>
                            <button type="submit" class="btn btn--secondary btn--sm btn--block">Aplicar</button>
                        </fieldset>
                    </form>

                    <?php if (!$isSearch && $categoryTree !== []): ?>
                    <nav class="filter-group filter-links" aria-label="Categorias">
                        <h2 class="filter-group__title">Categorias</h2>
                        <ul>
                            <li><a href="<?= e(url('/produtos')) ?>" <?= $activeCategory === null ? 'aria-current="page"' : '' ?>>Todos os produtos</a></li>
                            <?php foreach ($categoryTree as $node): ?>
                                <?php if ((int) $node['total'] === 0) { continue; } ?>
                                <li>
                                    <a href="<?= e(url('/categoria/' . $node['slug'])) ?>" <?= $activeId === $node['id'] ? 'aria-current="page"' : '' ?>>
                                        <?= e($node['name']) ?> <small><?= e($node['total']) ?></small>
                                    </a>
                                    <?php if ($node['children'] !== [] && ($activeId === $node['id'] || $activeParentId === $node['id'])): ?>
                                        <ul>
                                            <?php foreach ($node['children'] as $child): ?>
                                                <?php if ((int) $child['product_count'] === 0) { continue; } ?>
                                                <li><a href="<?= e(url('/categoria/' . $child['slug'])) ?>" <?= $activeId === $child['id'] ? 'aria-current="page"' : '' ?>><?= e($child['name']) ?> <small><?= e($child['product_count']) ?></small></a></li>
                                            <?php endforeach ?>
                                        </ul>
                                    <?php endif ?>
                                </li>
                            <?php endforeach ?>
                        </ul>
                    </nav>
                    <?php endif ?>
                </div>
            </details>
        </aside>

        <section class="catalog__results" aria-label="Produtos">
            <div class="catalog__toolbar">
                <p class="catalog__count" role="status">
                    <?php if ($paginator->total === 0): ?>
                        Nenhum produto encontrado.
                    <?php else: ?>
                        <strong><?= e($paginator->total) ?></strong> <?= $paginator->total === 1 ? 'produto' : 'produtos' ?>
                        <?php if ($paginator->hasPages()): ?> · página <?= e($paginator->page) ?> de <?= e($paginator->lastPage) ?><?php endif ?>
                    <?php endif ?>
                </p>
                <form class="catalog__sort" method="get" action="<?= e(url($path)) ?>">
                    <?php foreach ($params as $key => $value): ?>
                        <?php if ($value !== null && $key !== 'ordem'): ?>
                            <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
                        <?php endif ?>
                    <?php endforeach ?>
                    <label for="ordem">Ordenar por</label>
                    <select id="ordem" name="ordem" data-autosubmit>
                        <?php foreach ($sortLabels as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach ?>
                    </select>
                    <noscript><button type="submit" class="btn btn--secondary btn--sm">Ordenar</button></noscript>
                </form>
            </div>

            <?php if ($chips !== []): ?>
                <ul class="active-filters" aria-label="Filtros aplicados">
                    <?php foreach ($chips as $chip): ?>
                        <li><a class="chip chip--remove" href="<?= e($chip['url']) ?>" aria-label="Remover filtro: <?= e($chip['label']) ?>"><?= e($chip['label']) ?></a></li>
                    <?php endforeach ?>
                    <li><a class="chip" href="<?= e(query_url($path, ['q' => $params['q']])) ?>">Limpar filtros</a></li>
                </ul>
            <?php endif ?>

            <?php if ($products === []): ?>
                <div class="empty-state">
                    <span class="empty-state__icon"><?= $this->partial('partials/icon', ['name' => 'search', 'size' => 30]) ?></span>
                    <?php if ($isSearch): ?>
                        <h2 class="empty-state__title">Nada encontrado para “<?= e($filters['q']) ?>”</h2>
                        <p>Não encontramos produtos para “<?= e($filters['q']) ?>”. Tente outra palavra ou veja todo o catálogo.</p>
                    <?php elseif ($hasFilters): ?>
                        <h2 class="empty-state__title">Nenhum produto com esses filtros</h2>
                        <p>Tente remover um filtro ou ampliar a faixa de preço.</p>
                    <?php else: ?>
                        <h2 class="empty-state__title">Ainda não há produtos aqui</h2>
                        <p>Novidades chegam em breve.</p>
                    <?php endif ?>
                    <a class="btn btn--secondary" href="<?= e(url('/produtos')) ?>">Ver todos os produtos</a>
                </div>
            <?php else: ?>
                <div class="product-grid product-grid--3">
                    <?php foreach ($products as $product): ?>
                        <?= $this->partial('partials/product-card', ['product' => $product]) ?>
                    <?php endforeach ?>
                </div>
                <?= $this->partial('partials/pagination', ['paginator' => $paginator, 'path' => $path, 'params' => $params]) ?>
            <?php endif ?>
        </section>
    </div>
    <?php endif ?>
</div>
