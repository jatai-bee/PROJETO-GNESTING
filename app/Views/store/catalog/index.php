<?php
/**
 * Listagem: todos os produtos, categoria ou busca.
 * @var string      $heading
 * @var string|null $intro
 * @var list<array> $products
 * @var \GNesting\Core\Paginator|null $paginator null = busca sem termo
 * @var array       $filters   normalizados (q, terms, min_cents, max_cents, sort)
 * @var array       $params    filtros atuais para links (q, min, max, ordem)
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
$activeId = $activeCategory['id'] ?? null;
$activeParentId = $activeCategory['parent_id'] ?? null;
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
                <?php foreach ($activeCategory['children'] as $child): ?>
                    <li><a class="chip" href="<?= e(url('/categoria/' . $child['slug'])) ?>"><?= e($child['name']) ?></a></li>
                <?php endforeach ?>
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
                <summary class="filters__toggle">Filtrar e ordenar</summary>
                <form method="get" action="<?= e(url($path)) ?>" class="filters__form">
                    <?php if ($isSearch): ?>
                        <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
                    <?php endif ?>

                    <div class="field">
                        <label for="ordem">Ordenar por</label>
                        <select id="ordem" name="ordem" data-autosubmit>
                            <?php foreach ($sortLabels as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>

                    <fieldset class="filters__price">
                        <legend>Preço</legend>
                        <div class="filters__price-row">
                            <div class="field">
                                <label for="min">Mínimo</label>
                                <div class="field__control--prefix">
                                    <span class="field__prefix">R$</span>
                                    <input id="min" name="min" inputmode="decimal" value="<?= e($params['min'] ?? '') ?>" placeholder="0">
                                </div>
                            </div>
                            <div class="field">
                                <label for="max">Máximo</label>
                                <div class="field__control--prefix">
                                    <span class="field__prefix">R$</span>
                                    <input id="max" name="max" inputmode="decimal" value="<?= e($params['max'] ?? '') ?>" placeholder="—">
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <div class="filters__actions">
                        <button type="submit" class="btn btn--secondary btn--sm">Aplicar</button>
                        <?php if ($hasFilters): ?>
                            <a href="<?= e(query_url($path, ['q' => $params['q']])) ?>">Limpar filtros</a>
                        <?php endif ?>
                    </div>
                </form>
            </details>

            <?php if (!$isSearch && $categoryTree !== []): ?>
            <nav class="filters__categories" aria-label="Categorias">
                <h2 class="filters__heading">Categorias</h2>
                <ul>
                    <li><a href="<?= e(url('/produtos')) ?>" <?= $activeCategory === null ? 'aria-current="page"' : '' ?>>Todos os produtos</a></li>
                    <?php foreach ($categoryTree as $node): ?>
                        <?php if ($node['total'] === 0) { continue; } ?>
                        <li>
                            <a href="<?= e(url('/categoria/' . $node['slug'])) ?>" <?= $activeId === $node['id'] ? 'aria-current="page"' : '' ?>>
                                <?= e($node['name']) ?> <span class="muted">(<?= e($node['total']) ?>)</span>
                            </a>
                            <?php if ($node['children'] !== [] && ($activeId === $node['id'] || $activeParentId === $node['id'])): ?>
                                <ul>
                                    <?php foreach ($node['children'] as $child): ?>
                                        <li><a href="<?= e(url('/categoria/' . $child['slug'])) ?>" <?= $activeId === $child['id'] ? 'aria-current="page"' : '' ?>><?= e($child['name']) ?></a></li>
                                    <?php endforeach ?>
                                </ul>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ul>
            </nav>
            <?php endif ?>
        </aside>

        <section class="catalog__results" aria-label="Produtos">
            <p class="catalog__count muted" role="status">
                <?php if ($paginator->total === 0): ?>
                    Nenhum produto encontrado.
                <?php else: ?>
                    <?= e($paginator->total) ?> <?= $paginator->total === 1 ? 'produto' : 'produtos' ?>
                    <?php if ($paginator->hasPages()): ?> · página <?= e($paginator->page) ?> de <?= e($paginator->lastPage) ?><?php endif ?>
                <?php endif ?>
            </p>

            <?php if ($products === []): ?>
                <div class="empty-state">
                    <?php if ($isSearch): ?>
                        <p>Não encontramos produtos para “<?= e($filters['q']) ?>”. Tente outra palavra ou veja todo o catálogo.</p>
                    <?php elseif ($hasFilters): ?>
                        <p>Nenhum produto nesta faixa de preço.</p>
                    <?php else: ?>
                        <p>Ainda não há produtos aqui. Novidades chegam em breve.</p>
                    <?php endif ?>
                    <a class="btn btn--secondary" href="<?= e(url('/produtos')) ?>">Ver todos os produtos</a>
                </div>
            <?php else: ?>
                <div class="product-grid">
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
