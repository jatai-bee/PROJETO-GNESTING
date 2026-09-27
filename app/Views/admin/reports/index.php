<?php
/**
 * Relatórios por período: vendas, produtos, categorias, clientes e produção, com exportação em CSV.
 * @var string $tab
 * @var array{preset: ?string, start: DateTimeImmutable, end: DateTimeImmutable, days: int, label: string} $period
 * @var array<string, mixed> $data  ReportService::data()
 * @var array<string, ?string> $query período atual para os links
 */
use GNesting\Services\ReportService;

$link = static fn (array $extra): string => query_url('/admin/relatorios', $extra + $query);
$short = static function (int $cents): string {
    $reais = $cents / 100;

    return $reais >= 1000 ? 'R$ ' . rtrim(rtrim(number_format($reais / 1000, $reais < 10000 ? 1 : 0, ',', '.'), '0'), ',') . ' mil' : 'R$ ' . number_format($reais, 0, ',', '.');
};
$pct = static fn (int $part, int $total): string => $total > 0 ? (string) round($part * 100 / $total) . '%' : '—';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Relatórios</h1>
        <p class="muted">Vendas contam pela data do pagamento; pedidos cancelados ficam de fora. Período: <strong><?= e($period['label']) ?></strong> (<?= e($period['days']) ?> dias).</p>
    </div>
    <a class="btn btn--secondary btn--sm" href="<?= e($link(['aba' => $tab, 'formato' => 'csv'])) ?>">Baixar CSV</a>
</div>

<form method="get" action="<?= e(url('/admin/relatorios')) ?>" class="report-period panel">
    <input type="hidden" name="aba" value="<?= e($tab) ?>">
    <nav class="segmented" aria-label="Atalhos de período">
        <?php foreach (ReportService::PRESETS as $key => $label): ?>
            <a href="<?= e(query_url('/admin/relatorios', ['aba' => $tab, 'periodo' => $key])) ?>"<?= $period['preset'] === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach ?>
    </nav>
    <div class="report-period__custom">
        <label for="de">De</label>
        <input id="de" name="de" type="date" value="<?= e($period['start']->format('Y-m-d')) ?>">
        <label for="ate">até</label>
        <input id="ate" name="ate" type="date" value="<?= e($period['end']->format('Y-m-d')) ?>">
        <button type="submit" class="btn btn--secondary btn--sm">Aplicar</button>
    </div>
</form>

<nav class="tabs product-tabs" aria-label="Relatório">
    <?php foreach (ReportService::TABS as $key => $label): ?>
        <a href="<?= e($link(['aba' => $key === 'vendas' ? null : $key])) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach ?>
</nav>

<?php if ($tab === 'vendas'): ?>
    <?php $t = $data['totals']; $x = $data['extras']; ?>
    <section class="stats" aria-label="Resumo de vendas">
        <div class="stat"><span class="stat__label">Faturamento</span><span class="stat__value"><?= e(money($t['revenue'])) ?></span><span class="stat__meta">frete incluso: <?= e(money($x['shipping_cents'])) ?></span></div>
        <div class="stat"><span class="stat__label">Pedidos pagos</span><span class="stat__value"><?= e($t['orders']) ?></span><span class="stat__meta"><?= e($t['items']) ?> peças</span></div>
        <div class="stat"><span class="stat__label">Ticket médio</span><span class="stat__value"><?= e(money($t['average'])) ?></span><span class="stat__meta"><?= e($t['new_customers']) ?> cliente(s) novo(s)</span></div>
        <div class="stat"><span class="stat__label">Descontos e cancelamentos</span><span class="stat__value"><?= e(money($x['discount_cents'])) ?></span>
            <span class="stat__meta"><?= e($x['coupons']) ?> com cupom · <?= e($x['cancelled']) ?> cancelado(s) (<?= e(money($x['cancelled_cents'])) ?>)</span></div>
    </section>
    <section class="panel dash-section">
        <h2 class="panel__title">Faturamento por <?= $data['byMonth'] ? 'mês' : 'dia' ?></h2>
        <?= $this->partial('admin/partials/chart-bars', [
            'series' => array_map(static fn (array $d): array => [
                'label' => $d['label'], 'value' => $d['revenue'],
                'title' => $d['label'] . ': ' . money($d['revenue']) . ' · ' . $d['orders'] . ($d['orders'] === 1 ? ' pedido' : ' pedidos'),
            ], $data['chart']),
            'format' => $short,
            'caption' => 'Faturamento por ' . ($data['byMonth'] ? 'mês' : 'dia') . ' de ' . $period['label'],
        ]) ?>
    </section>
    <section class="panel">
        <h2 class="panel__title">Dia a dia</h2>
        <div class="table-wrap report-table">
            <table class="table">
                <thead><tr><th>Data</th><th class="table__num">Pedidos</th><th class="table__num">Faturamento</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse(array_filter($data['series'], static fn (array $d): bool => $d['orders'] > 0)) as $d): ?>
                    <tr><td><?= e(implode('/', array_reverse(explode('-', $d['date'])))) ?></td><td class="table__num"><?= e($d['orders']) ?></td><td class="table__num"><?= e(money($d['revenue'])) ?></td></tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php if ($t['orders'] === 0): ?><p class="muted">Nenhuma venda no período.</p><?php endif ?>
    </section>

<?php elseif ($tab === 'produtos'): ?>
    <?php $total = array_sum(array_column($data['rows'], 'revenue')); ?>
    <section class="panel">
        <?php if ($data['rows'] === []): ?>
            <p class="muted">Nenhuma venda no período.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>#</th><th>Produto</th><th class="table__num">Unidades</th><th class="table__num">Faturamento</th><th class="table__num">Participação</th><th class="table__num">Custo</th><th class="table__num">Margem</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['rows'] as $n => $r): ?>
                        <?php $m = $r['with_cost'] && $r['revenue'] > 0 ? (int) round(($r['revenue'] - $r['cost']) * 100 / $r['revenue']) : null; ?>
                        <tr>
                            <td class="muted"><?= e($n + 1) ?></td>
                            <td><?php if ($r['product_id'] !== null): ?><a href="<?= e(url('/admin/produtos/' . $r['product_id'] . '/editar')) ?>"><?= e($r['name']) ?></a><?php else: ?><?= e($r['name']) ?><?php endif ?></td>
                            <td class="table__num"><?= e($r['quantity']) ?></td>
                            <td class="table__num"><?= e(money($r['revenue'])) ?></td>
                            <td class="table__num"><?= e($pct($r['revenue'], $total)) ?></td>
                            <td class="table__num"><?= $r['with_cost'] ? e(money($r['cost'])) : '<span class="muted" title="Cadastre o custo da variação">—</span>' ?></td>
                            <td class="table__num"><?= $m === null ? '<span class="muted">—</span>' : '<span class="' . ($m < 20 ? 'text-warn' : '') . '">' . e($m) . '%</span>' ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <p class="muted table-summary">Custo e margem usam o custo cadastrado hoje em cada variação.</p>
        <?php endif ?>
    </section>

<?php elseif ($tab === 'categorias'): ?>
    <div class="dash-grid dash-grid--wide">
        <section class="panel">
            <?php if ($data['rows'] === []): ?>
                <p class="muted">Nenhuma venda no período.</p>
            <?php else: ?>
                <?php $total = array_sum(array_column($data['rows'], 'revenue')); ?>
                <table class="table">
                    <thead><tr><th>Categoria</th><th class="table__num">Unidades</th><th class="table__num">Faturamento</th><th class="table__num">Participação</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['rows'] as $r): ?>
                        <tr><td><?= e($r['name']) ?></td><td class="table__num"><?= e($r['quantity']) ?></td><td class="table__num"><?= e(money($r['revenue'])) ?></td><td class="table__num"><?= e($pct($r['revenue'], $total)) ?></td></tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            <?php endif ?>
        </section>
        <section class="panel">
            <h2 class="panel__title">Participação</h2>
            <?= $this->partial('admin/partials/bar-list', [
                'rows' => array_map(static fn (array $r): array => ['label' => $r['name'], 'value' => $r['revenue'], 'display' => money($r['revenue'])], $data['rows']),
                'empty' => 'Nenhuma venda no período.',
            ]) ?>
        </section>
    </div>

<?php elseif ($tab === 'clientes'): ?>
    <section class="panel">
        <?php if ($data['rows'] === []): ?>
            <p class="muted">Nenhuma venda no período.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>#</th><th>Cliente</th><th>Cidade</th><th class="table__num">Pedidos</th><th class="table__num">Total pago</th><th>Último pagamento</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['rows'] as $n => $r): ?>
                        <tr>
                            <td class="muted"><?= e($n + 1) ?></td>
                            <td><a href="<?= e(url('/admin/clientes/' . $r['customer_id'])) ?>"><?= e($r['name']) ?></a></td>
                            <td><?= e($r['city']) ?></td>
                            <td class="table__num"><?= e($r['orders']) ?></td>
                            <td class="table__num"><?= e(money($r['spent'])) ?></td>
                            <td><?= e(format_datetime($r['last_paid_at'], 'd/m/Y')) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>

<?php else: ?>
    <section class="stats" aria-label="Produção no período">
        <div class="stat"><span class="stat__label">Peças concluídas</span><span class="stat__value"><?= e($data['pieces']) ?></span><span class="stat__meta"><?= e($data['jobs']) ?> ordem(ns) de produção</span></div>
        <div class="stat"><span class="stat__label">No prazo</span><span class="stat__value"><?= $data['on_time_percent'] === null ? '—' : e($data['on_time_percent']) . '%' ?></span><span class="stat__meta">pronto até o prazo prometido</span></div>
        <div class="stat"><span class="stat__label">Tempo médio</span><span class="stat__value"><?= $data['avg_days'] === null ? '—' : e(str_replace('.', ',', (string) $data['avg_days'])) . ' dias' ?></span><span class="stat__meta">do pagamento até ficar pronto</span></div>
        <div class="stat<?= $data['reworked'] > 0 ? ' stat--alert' : '' ?>"><span class="stat__label">Retrabalho</span><span class="stat__value"><?= e($data['reworked']) ?></span><span class="stat__meta">ordem(ns) reprovada(s) no controle de qualidade</span></div>
    </section>
    <?php if ($data['jobs'] === 0): ?><section class="panel"><p class="muted">Nenhuma ordem de produção concluída no período.</p></section><?php endif ?>
<?php endif ?>
