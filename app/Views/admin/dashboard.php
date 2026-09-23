<?php
/**
 * @var array{products:int,active_products:int,categories:int,orders_open:int,customers:int} $counters
 * @var list<array<string, mixed>> $recentActivity
 * @var array{name:string} $currentAdmin
 */
$actionLabels = [
    'login' => 'Entrou no painel',
    'logout' => 'Saiu do painel',
    'login_failed' => 'Tentativa de login falhou',
    'create' => 'Criou',
    'update' => 'Alterou',
    'delete' => 'Excluiu',
    'price_change' => 'Alterou preço',
    'stock_change' => 'Alterou estoque',
    'status_change' => 'Alterou situação',
];
$entityLabels = [
    'admin' => 'Usuário do painel', 'user' => 'Usuário', 'category' => 'Categoria',
    'product' => 'Produto', 'product_image' => 'Imagem de produto',
];
?>
<?php
$canCatalog = \GNesting\Enums\AdminRole::tryFrom($currentAdmin['role'])?->isAllowed(['manager']) ?? false;
?>
<h1 class="page-title">Olá, <?= e(explode(' ', $currentAdmin['name'])[0]) ?>.</h1>

<?php if ($canCatalog && $counters['active_without_image'] > 0): ?>
    <p class="alert alert--warn" role="status">
        <?= e($counters['active_without_image']) ?> produto(s) ativo(s) sem imagem.
        <a href="<?= e(url('/admin/produtos?status=active')) ?>">Revisar produtos</a>
    </p>
<?php endif ?>

<section class="stats" aria-label="Indicadores">
    <div class="stat"><span class="stat__label">Produtos ativos</span><span class="stat__value"><?= e($counters['active_products']) ?></span>
        <span class="stat__meta">de <?= e($counters['products']) ?> cadastrados<?php if ($canCatalog): ?> · <a href="<?= e(url('/admin/produtos')) ?>">ver</a><?php endif ?></span></div>
    <div class="stat"><span class="stat__label">Categorias</span><span class="stat__value"><?= e($counters['categories']) ?></span>
        <?php if ($canCatalog): ?><span class="stat__meta"><a href="<?= e(url('/admin/categorias')) ?>">gerenciar</a></span><?php endif ?></div>
    <div class="stat"><span class="stat__label">Pedidos em aberto</span><span class="stat__value"><?= e($counters['orders_open']) ?></span></div>
    <div class="stat"><span class="stat__label">Clientes</span><span class="stat__value"><?= e($counters['customers']) ?></span></div>
</section>

<section class="panel">
    <h2 class="panel__title">Atividade recente</h2>
    <?php if ($recentActivity === []): ?>
        <p class="muted">Nenhum evento registrado ainda.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Item</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($recentActivity as $log): ?>
                    <tr>
                        <td><?= e(format_datetime($log['created_at'])) ?></td>
                        <td><?= e($log['admin_name'] ?? '—') ?></td>
                        <td><?= e($actionLabels[$log['action']] ?? $log['action']) ?></td>
                        <td><?= e($log['entity_type'] ? ($entityLabels[$log['entity_type']] ?? $log['entity_type']) . ($log['entity_id'] ? ' #' . $log['entity_id'] : '') : '—') ?></td>
                        <td><?= e($log['ip_address'] ?? '—') ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>
