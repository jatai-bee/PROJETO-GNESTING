<?php
/**
 * Visão geral do painel.
 * @var array{name:string, role:string} $currentAdmin
 * @var bool $canSales
 * @var bool $canOrders
 * @var bool $canStock
 * @var array<string, mixed>|null $sales       DashboardService::sales()
 * @var array<string, int> $statusCounts
 * @var array<string, mixed>|null $alerts      DashboardRepository::alerts()
 * @var list<array<string, mixed>> $recentOrders
 * @var array<string, int> $counters
 * @var list<array<string, mixed>> $recentActivity
 */
use GNesting\Enums\OrderStatus;
use GNesting\Services\DashboardService;
use GNesting\Services\MaterialService;

$icon = fn (string $name, int $size = 18): string => $this->partial('partials/icon', ['name' => $name, 'size' => $size]);
// R$ abreviado para o eixo do gráfico: R$ 850 · R$ 1,2 mil · R$ 12 mil
$short = static function (int $cents): string {
    $reais = $cents / 100;
    if ($reais >= 1000) {
        $thousands = $reais / 1000;

        return 'R$ ' . rtrim(rtrim(number_format($thousands, $thousands < 10 ? 1 : 0, ',', '.'), '0'), ',') . ' mil';
    }

    return 'R$ ' . number_format($reais, 0, ',', '.');
};
$delta = static function (int $current, int $previous): string {
    $change = DashboardService::change($current, $previous);
    if ($change === null) {
        return '<span class="delta delta--none">sem base anterior</span>';
    }
    $class = $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat');
    $arrow = $change > 0 ? '▲' : ($change < 0 ? '▼' : '■');

    return '<span class="delta delta--' . $class . '"><span aria-hidden="true">' . $arrow . '</span> ' . abs($change) . '%<span class="visually-hidden"> '
        . ($change >= 0 ? 'a mais' : 'a menos') . '</span></span> <span class="muted">vs. período anterior</span>';
};
$pipeline = [
    ['awaiting_payment', 'Aguardando pagamento', ['awaiting_payment']],
    ['production_pending', 'Na fila', ['paid', 'production_pending']],
    ['in_production', 'Corte e montagem', ['in_production']],
    ['finishing', 'Acabamento', ['finishing']],
    ['quality_control', 'Controle de qualidade', ['quality_control']],
    ['packaging', 'Embalagem', ['packaging']],
    ['ready_to_ship', 'Pronto para envio', ['ready_to_ship']],
    ['shipped', 'Em trânsito', ['shipped']],
];
$qty = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
$unitLabel = static fn (string $unit, float $n): string => match ($unit) {
    'sheet' => $n === 1.0 ? 'chapa' : 'chapas',
    'unit' => $n === 1.0 ? 'unidade' : 'unidades',
    default => MaterialService::UNITS[$unit] ?? $unit,
};
$firstName = explode(' ', trim($currentAdmin['name']))[0];
?>
<div class="page-header dash-head">
    <div>
        <h1 class="page-title">Visão geral</h1>
        <p class="muted">Olá, <?= e($firstName) ?>.<?php if ($sales !== null): ?> Vendas de <?= e($sales['start']->format('d/m')) ?> a <?= e($sales['end']->format('d/m/Y')) ?>.<?php endif ?></p>
    </div>
    <?php if ($sales !== null): ?>
    <nav class="segmented" aria-label="Período">
        <?php foreach (DashboardService::PERIODS as $key => $label): ?>
            <a href="<?= e(query_url('/admin', ['periodo' => $key === DashboardService::DEFAULT_PERIOD ? null : $key])) ?>"<?= $sales['period'] === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach ?>
    </nav>
    <?php endif ?>
</div>

<?php if ($sales !== null): ?>
    <?php $t = $sales['totals']; $p = $sales['previous']; ?>
    <section class="stats" aria-label="Vendas do período">
        <div class="stat"><span class="stat__label">Faturamento</span><span class="stat__value"><?= e(money($t['revenue'])) ?></span><span class="stat__meta"><?= $delta($t['revenue'], $p['revenue']) ?></span></div>
        <div class="stat"><span class="stat__label">Pedidos pagos</span><span class="stat__value"><?= e($t['orders']) ?></span><span class="stat__meta"><?= $delta($t['orders'], $p['orders']) ?></span></div>
        <div class="stat"><span class="stat__label">Ticket médio</span><span class="stat__value"><?= e(money($t['average'])) ?></span><span class="stat__meta"><?= $delta($t['average'], $p['average']) ?></span></div>
        <div class="stat"><span class="stat__label">Novos clientes</span><span class="stat__value"><?= e($t['new_customers']) ?></span><span class="stat__meta"><?= $delta($t['new_customers'], $p['new_customers']) ?></span></div>
    </section>

    <div class="dash-grid dash-grid--wide">
        <section class="panel" aria-labelledby="h-vendas">
            <div class="panel__header">
                <h2 id="h-vendas" class="panel__title">Vendas por dia</h2>
                <span class="muted"><?= e($t['items']) ?> <?= $t['items'] === 1 ? 'peça vendida' : 'peças vendidas' ?></span>
            </div>
            <?= $this->partial('admin/partials/chart-bars', [
                'series' => array_map(static fn (array $d): array => [
                    'label' => $d['label'], 'value' => $d['revenue'],
                    'title' => $d['label'] . ': ' . money($d['revenue']) . ' · ' . $d['orders'] . ($d['orders'] === 1 ? ' pedido' : ' pedidos'),
                ], $sales['series']),
                'format' => $short,
                'caption' => 'Faturamento por dia, ' . mb_strtolower($sales['label']) . ': total ' . money($t['revenue']),
            ]) ?>
        </section>
        <section class="panel" aria-labelledby="h-categorias">
            <h2 id="h-categorias" class="panel__title">Vendas por categoria</h2>
            <?php $catTotal = max(1, array_sum(array_column($sales['categories'], 'revenue'))); ?>
            <?= $this->partial('admin/partials/bar-list', [
                'rows' => array_map(static fn (array $c): array => [
                    'label' => $c['name'], 'value' => $c['revenue'], 'display' => (int) round($c['revenue'] * 100 / $catTotal) . '%', 'meta' => money($c['revenue']),
                ], $sales['categories']),
                'empty' => 'Nenhuma venda no período.',
            ]) ?>
        </section>
    </div>
<?php elseif ($canOrders): ?>
    <section class="stats" aria-label="Pedidos">
        <div class="stat"><span class="stat__label">Pedidos em aberto</span><span class="stat__value"><?= e($counters['orders_open']) ?></span></div>
        <div class="stat"><span class="stat__label">Na produção</span><span class="stat__value"><?= e(array_sum(array_intersect_key($statusCounts, array_flip(['production_pending', 'in_production', 'finishing', 'quality_control', 'packaging'])))) ?></span></div>
        <div class="stat"><span class="stat__label">Prontos para envio</span><span class="stat__value"><?= e($statusCounts['ready_to_ship'] ?? 0) ?></span></div>
        <div class="stat"><span class="stat__label">Aguardando pagamento</span><span class="stat__value"><?= e($statusCounts['awaiting_payment'] ?? 0) ?></span></div>
    </section>
<?php endif ?>

<?php if ($canOrders): ?>
<section class="panel dash-section" aria-labelledby="h-etapas">
    <div class="panel__header">
        <h2 id="h-etapas" class="panel__title">Pedidos por etapa</h2>
        <a class="link-arrow" href="<?= e(url('/admin/pedidos?status=open')) ?>">Todos os pedidos em aberto</a>
    </div>
    <ol class="pipeline">
        <?php foreach ($pipeline as [$filter, $label, $statuses]): ?>
            <?php $n = array_sum(array_intersect_key($statusCounts, array_flip($statuses))); ?>
            <li class="pipeline__step<?= $n > 0 ? ' pipeline__step--busy' : '' ?>">
                <a href="<?= e(url('/admin/pedidos?status=' . $filter)) ?>">
                    <span class="pipeline__count"><?= e($n) ?></span>
                    <span class="pipeline__label"><?= e($label) ?></span>
                </a>
            </li>
        <?php endforeach ?>
    </ol>
</section>
<?php endif ?>

<div class="dash-grid">
    <?php if ($sales !== null): ?>
    <section class="panel" aria-labelledby="h-top">
        <h2 id="h-top" class="panel__title">Mais vendidos</h2>
        <?= $this->partial('admin/partials/bar-list', [
            'rows' => array_map(static fn (array $r): array => [
                'label' => $r['name'], 'value' => $r['revenue'], 'display' => money($r['revenue']),
                'meta' => $r['quantity'] . ($r['quantity'] === 1 ? ' unidade' : ' unidades'),
                'href' => $r['product_id'] !== null ? url('/admin/produtos/' . $r['product_id'] . '/editar') : null,
            ], $sales['top']),
            'empty' => 'Nenhuma venda no período.',
        ]) ?>
    </section>
    <?php endif ?>

    <?php if ($alerts !== null): ?>
    <section class="panel" aria-labelledby="h-alertas">
        <h2 id="h-alertas" class="panel__title">Precisa de atenção</h2>
        <?php $hasAlert = $alerts['low_materials'] !== [] || $alerts['sold_out'] > 0 || $alerts['stale_payments'] > 0 || ($canSales && $alerts['without_image'] > 0); ?>
        <?php if (!$hasAlert): ?>
            <p class="muted">Tudo em ordem: estoque acima do mínimo e nenhum pedido parado.</p>
        <?php else: ?>
            <ul class="alert-list">
                <?php foreach ($alerts['low_materials'] as $m): ?>
                    <li class="alert-list__item alert-list__item--<?= $m['stock'] <= 0 ? 'critical' : 'warn' ?>">
                        <strong><?= e($m['name']) ?></strong>
                        <span><?= $m['stock'] <= 0 ? 'sem estoque' : e($qty($m['stock']) . ' ' . $unitLabel($m['unit'], $m['stock']) . ' (mínimo ' . $qty($m['reorder']) . ')') ?></span>
                        <a href="<?= e(url('/admin/materiais')) ?>">repor</a>
                    </li>
                <?php endforeach ?>
                <?php if ($alerts['low_materials_total'] > count($alerts['low_materials'])): ?>
                    <li class="alert-list__more"><a href="<?= e(url('/admin/materiais')) ?>">Ver as <?= e($alerts['low_materials_total']) ?> matérias-primas abaixo do mínimo</a></li>
                <?php endif ?>
                <?php if ($alerts['sold_out'] > 0): ?>
                    <li class="alert-list__item alert-list__item--warn"><strong><?= e($alerts['sold_out']) ?> variação(ões) de pronta entrega zerada(s)</strong><span>os clientes veem "esgotado"</span><?php if ($canSales): ?><a href="<?= e(url('/admin/produtos')) ?>">ver produtos</a><?php endif ?></li>
                <?php endif ?>
                <?php if ($alerts['stale_payments'] > 0): ?>
                    <li class="alert-list__item alert-list__item--warn"><strong><?= e($alerts['stale_payments']) ?> pedido(s) sem pagamento há mais de 1 dia</strong><span>cancelados sozinhos depois do prazo</span><a href="<?= e(url('/admin/pedidos?status=awaiting_payment')) ?>">ver</a></li>
                <?php endif ?>
                <?php if ($canSales && $alerts['without_image'] > 0): ?>
                    <li class="alert-list__item alert-list__item--warn"><strong><?= e($alerts['without_image']) ?> produto(s) ativo(s) sem foto</strong><span>não aparecem bem na loja</span><a href="<?= e(url('/admin/produtos?status=active')) ?>">revisar</a></li>
                <?php endif ?>
            </ul>
        <?php endif ?>
    </section>
    <?php endif ?>

    <?php if ($canOrders): ?>
    <section class="panel" aria-labelledby="h-ultimos">
        <div class="panel__header">
            <h2 id="h-ultimos" class="panel__title">Últimos pedidos</h2>
            <a class="link-arrow" href="<?= e(url('/admin/pedidos')) ?>">Ver todos</a>
        </div>
        <?php if ($recentOrders === []): ?>
            <p class="muted">Nenhum pedido ainda.</p>
        <?php else: ?>
            <ul class="order-feed">
                <?php foreach ($recentOrders as $o): ?>
                    <?php $st = OrderStatus::tryFrom((string) $o['status']); ?>
                    <li>
                        <a href="<?= e(url('/admin/pedidos/' . $o['id'])) ?>">
                            <span class="order-feed__main"><strong class="mono"><?= e($o['number']) ?></strong><small><?= e($o['customer_name']) ?> · <?= e(format_datetime($o['placed_at'], 'd/m H:i')) ?></small></span>
                            <span class="order-feed__side"><strong><?= e(money((int) $o['total_cents'])) ?></strong><span class="order-status-tag order-status-tag--<?= e($o['status']) ?>"><?= e($st?->label() ?? $o['status']) ?></span></span>
                        </a>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>
    <?php endif ?>
</div>

<?php if ($recentActivity !== []): ?>
<section class="panel dash-section" aria-labelledby="h-atividade">
    <div class="panel__header">
        <h2 id="h-atividade" class="panel__title">Atividade da equipe</h2>
        <a class="link-arrow" href="<?= e(url('/admin/logs')) ?>">Auditoria completa</a>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Item</th></tr></thead>
            <tbody>
            <?php
            $actionLabels = ['login' => 'Entrou no painel', 'logout' => 'Saiu do painel', 'login_failed' => 'Tentativa de login falhou', 'create' => 'Criou',
                'update' => 'Alterou', 'delete' => 'Excluiu', 'price_change' => 'Alterou preço', 'stock_change' => 'Alterou estoque', 'status_change' => 'Alterou situação'];
            $entityLabels = ['admin' => 'Usuário do painel', 'user' => 'Usuário', 'category' => 'Categoria', 'product' => 'Produto', 'product_image' => 'Imagem de produto',
                'product_variant' => 'Variação', 'personalization_rule' => 'Campo de personalização', 'production_spec' => 'Ficha de produção',
                'production_file' => 'Arquivo de produção', 'material' => 'Material', 'order' => 'Pedido', 'demo_data' => 'Dados de demonstração'];
            ?>
            <?php foreach ($recentActivity as $log): ?>
                <tr>
                    <td><?= e(format_datetime($log['created_at'])) ?></td>
                    <td><?= e($log['admin_name'] ?? '—') ?></td>
                    <td><?= e($actionLabels[$log['action']] ?? $log['action']) ?></td>
                    <td><?= e($log['entity_type'] ? ($entityLabels[$log['entity_type']] ?? $log['entity_type']) . ($log['entity_id'] ? ' #' . $log['entity_id'] : '') : '—') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif ?>
