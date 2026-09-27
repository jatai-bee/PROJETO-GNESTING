<?php
/**
 * Estoque: produto acabado (pronta entrega) e matéria-prima. Em falta primeiro; acerto de contagem na linha.
 * @var string $tab     produtos | materia-prima
 * @var string $filter  pronta | alerta | todos
 * @var array<string, int> $summary
 * @var list<array<string, mixed>> $goods
 * @var list<array<string, mixed>> $materials
 * @var string $currentPath
 */
use GNesting\Services\MaterialService;

$stateTag = [
    'out' => ['Sem estoque', 'fail'],
    'low' => ['Abaixo do mínimo', 'warn'],
    'ok' => ['Em estoque', 'on'],
    'made' => ['Sob encomenda', 'off'],
];
$qty = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
$back = $currentPath . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : '');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Estoque</h1>
        <p class="muted">Produto acabado de pronta entrega e matéria-prima. O que está em falta aparece primeiro.</p>
    </div>
</div>

<section class="stats" aria-label="Resumo do estoque">
    <div class="stat"><span class="stat__label">Pronta entrega</span><span class="stat__value"><?= e($summary['units']) ?> un.</span>
        <span class="stat__meta"><?= e($summary['variants']) ?> variações · custo <?= e(money($summary['value_cents'])) ?></span></div>
    <div class="stat<?= $summary['out'] + $summary['low'] > 0 ? ' stat--alert' : '' ?>"><span class="stat__label">Produto em falta</span>
        <span class="stat__value"><?= e($summary['out'] + $summary['low']) ?></span>
        <span class="stat__meta"><?= e($summary['out']) ?> sem estoque · <?= e($summary['low']) ?> abaixo do mínimo</span></div>
    <div class="stat<?= $summary['materials_low'] + $summary['materials_out'] > 0 ? ' stat--alert' : '' ?>"><span class="stat__label">Matéria-prima em falta</span>
        <span class="stat__value"><?= e($summary['materials_low'] + $summary['materials_out']) ?></span>
        <span class="stat__meta"><?= e($summary['materials_out']) ?> zerada(s) · <?= e($summary['materials_low']) ?> no ponto de reposição</span></div>
    <div class="stat"><span class="stat__label">Matéria-prima em estoque</span><span class="stat__value"><?= e(money($summary['materials_value_cents'])) ?></span>
        <span class="stat__meta">pelo custo cadastrado</span></div>
</section>

<nav class="tabs product-tabs" aria-label="Tipo de estoque">
    <a href="<?= e(url('/admin/estoque')) ?>"<?= $tab === 'produtos' ? ' aria-current="page"' : '' ?>>Produto acabado</a>
    <a href="<?= e(url('/admin/estoque?aba=materia-prima')) ?>"<?= $tab === 'materia-prima' ? ' aria-current="page"' : '' ?>>Matéria-prima</a>
</nav>

<?php if ($tab === 'produtos'): ?>
    <nav class="segmented stock-filter" aria-label="Mostrar">
        <?php foreach (['pronta' => 'Pronta entrega', 'alerta' => 'Só em falta', 'todos' => 'Todos os produtos'] as $key => $label): ?>
            <a href="<?= e(query_url('/admin/estoque', ['filtro' => $key === 'pronta' ? null : $key])) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach ?>
    </nav>

    <section class="panel">
        <?php if ($goods === []): ?>
            <p class="muted"><?= $filter === 'alerta' ? 'Nada em falta: todo produto de pronta entrega está acima do mínimo.' : 'Nenhum produto de pronta entrega. Produtos sob encomenda não controlam quantidade.' ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table stock-table">
                    <thead><tr><th><span class="visually-hidden">Foto</span></th><th>Produto</th><th>Situação</th><th class="table__num">Disponível</th><th class="table__num">Reservado</th><th>Acerto de contagem</th></tr></thead>
                    <tbody>
                    <?php foreach ($goods as $g): ?>
                        <?php [$label, $tone] = $stateTag[$g['state']]; ?>
                        <tr>
                            <td class="table__thumb"><?php if ($g['cover_path']): ?><img src="<?= e(upload_url($g['cover_path'], 400)) ?>" alt="" width="48" height="48" loading="lazy"><?php else: ?><span class="thumb-placeholder">—</span><?php endif ?></td>
                            <td class="table--wrap">
                                <a href="<?= e(url("/admin/produtos/{$g['product_id']}/editar#estoque")) ?>"><strong><?= e($g['name']) ?></strong></a><?php if ($g['variant_name']): ?> <span class="muted">· <?= e($g['variant_name']) ?></span><?php endif ?>
                                <br><code><?= e($g['sku']) ?></code><?php if (!$g['is_active']): ?> <span class="badge">inativo</span><?php endif ?>
                            </td>
                            <td><span class="status status--<?= e($tone) ?>"><?= e($label) ?></span></td>
                            <td class="table__num"><?= $g['stock_mode'] === 'stock' ? '<strong>' . e($g['available']) . '</strong>' . ($g['reorder_level'] !== null ? '<br><small class="muted">mín. ' . e($g['reorder_level']) . '</small>' : '') : '—' ?></td>
                            <td class="table__num"><?= $g['stock_mode'] === 'stock' ? e($g['quantity_reserved']) : '—' ?></td>
                            <td>
                                <?php if ($g['stock_mode'] === 'stock'): ?>
                                    <form method="post" action="<?= e(url('/admin/estoque/' . $g['variant_id'] . '/ajuste')) ?>" class="stock-adjust">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="voltar" value="<?= e($back) ?>">
                                        <label><span>Contagem</span><input name="quantidade" inputmode="numeric" value="<?= e($g['quantity_on_hand']) ?>" size="4" aria-label="Quantidade física de <?= e($g['name']) ?>"></label>
                                        <label><span>Mínimo</span><input name="minimo" inputmode="numeric" value="<?= e($g['reorder_level'] ?? '') ?>" size="3" aria-label="Estoque mínimo de <?= e($g['name']) ?>"></label>
                                        <label class="stock-adjust__reason"><span>Motivo</span><input name="motivo" maxlength="200" placeholder="Contagem, lote produzido…" aria-label="Motivo do acerto"></label>
                                        <button type="submit" class="btn btn--secondary btn--sm">Salvar</button>
                                    </form>
                                    <?php if ($g['last_movement']): ?><small class="muted">última movimentação <?= e(format_datetime($g['last_movement'], 'd/m/Y')) ?></small><?php endif ?>
                                <?php else: ?>
                                    <span class="muted">Feito após o pedido</span>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>
<?php else: ?>
    <section class="panel">
        <div class="panel__header">
            <h2 class="panel__title">Matéria-prima</h2>
            <a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/materiais/novo')) ?>">Nova matéria-prima</a>
        </div>
        <?php if ($materials === []): ?>
            <p class="muted">Nenhuma matéria-prima cadastrada.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Material</th><th>Situação</th><th>Estoque</th><th class="table__num">Custo</th><th class="table__num">Fichas</th><th><span class="visually-hidden">Ações</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($materials as $m): ?>
                        <?php
                        $stock = (float) $m['stock_qty'];
                        $reorder = $m['reorder_level'] !== null ? (float) $m['reorder_level'] : null;
                        [$label, $tone] = !$m['is_active'] ? ['Inativa', 'off'] : ($stock <= 0 ? ['Sem estoque', 'fail'] : ($reorder !== null && $stock <= $reorder ? ['Repor', 'warn'] : ['Em estoque', 'on']));
                        $unit = mb_strtolower(MaterialService::UNITS[$m['unit']] ?? $m['unit']);
                        $scale = max($stock, ($reorder ?? 0) * 2, 1);
                        ?>
                        <tr>
                            <td><a href="<?= e(url('/admin/materiais/' . $m['id'] . '/editar')) ?>"><strong><?= e($m['name']) ?></strong></a><br><code><?= e($m['code']) ?></code></td>
                            <td><span class="status status--<?= e($tone) ?>"><?= e($label) ?></span></td>
                            <td class="stock-level">
                                <span><strong><?= e($qty($stock)) ?></strong> <?= e($unit) ?><?= $reorder !== null ? ' <small class="muted">(mín. ' . e($qty($reorder)) . ')</small>' : '' ?></span>
                                <svg viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true">
                                    <rect x="0" y="0" width="100" height="6" class="bar-list__track"/>
                                    <rect x="0" y="0" width="<?= round(min(100, $stock / $scale * 100), 2) ?>" height="6" class="stock-level__fill stock-level__fill--<?= e($tone) ?>"/>
                                    <?php if ($reorder !== null): ?><rect x="<?= round(min(99.5, $reorder / $scale * 100), 2) ?>" y="0" width="0.6" height="6" class="stock-level__mark"/><?php endif ?>
                                </svg>
                            </td>
                            <td class="table__num"><?= $m['cost_cents'] !== null ? e(money((int) $m['cost_cents'])) . ' <small class="muted">/ ' . e($unit) . '</small>' : '—' ?></td>
                            <td class="table__num"><?= e($m['spec_count']) ?></td>
                            <td class="table__actions"><a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/materiais/' . $m['id'] . '/editar#movimento')) ?>">Entrada ou saída</a></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>
<?php endif ?>
