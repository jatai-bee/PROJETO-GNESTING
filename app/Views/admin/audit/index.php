<?php
/**
 * @var list<array<string, mixed>> $logs
 * @var \GNesting\Core\Paginator $paginator
 * @var array{action:string, entity_type:string} $filters
 * @var list<string> $actions
 * @var list<string> $entityTypes
 */
$actionLabels = [
    'login' => 'Entrou no painel', 'logout' => 'Saiu do painel', 'login_failed' => 'Falha de login',
    'create' => 'Criação', 'update' => 'Alteração', 'delete' => 'Exclusão', 'price_change' => 'Alteração de preço',
    'stock_change' => 'Alteração de estoque', 'status_change' => 'Mudança de situação',
];
$entityLabels = [
    'admin' => 'Usuário do painel', 'user' => 'Usuário', 'category' => 'Categoria', 'product' => 'Produto',
    'product_image' => 'Imagem de produto',
];
$params = ['acao' => $filters['action'], 'tipo' => $filters['entity_type']];
$pretty = static fn (?string $json): string => $json === null ? '' : (string) json_encode(json_decode($json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<h1 class="page-title">Auditoria</h1>

<form method="get" action="<?= e(url('/admin/logs')) ?>" class="filters">
    <div class="field">
        <label for="acao">Ação</label>
        <select id="acao" name="acao">
            <option value="">Todas</option>
            <?php foreach ($actions as $action): ?>
                <option value="<?= e($action) ?>"<?= $filters['action'] === $action ? ' selected' : '' ?>><?= e($actionLabels[$action] ?? $action) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="field">
        <label for="tipo">Item</label>
        <select id="tipo" name="tipo">
            <option value="">Todos</option>
            <?php foreach ($entityTypes as $type): ?>
                <option value="<?= e($type) ?>"<?= $filters['entity_type'] === $type ? ' selected' : '' ?>><?= e($entityLabels[$type] ?? $type) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="filters__actions"><button type="submit" class="btn btn--secondary">Filtrar</button></div>
</form>

<section class="panel">
    <?php if ($logs === []): ?>
        <p class="muted">Nenhum evento encontrado.</p>
    <?php else: ?>
        <p class="muted table-summary"><?= e($paginator->from()) ?>–<?= e($paginator->to()) ?> de <?= e($paginator->total) ?> evento(s)</p>
        <div class="table-wrap">
            <table class="table table--wrap">
                <thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Item</th><th>Detalhes</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_datetime($log['created_at'], 'd/m/Y H:i:s')) ?></td>
                        <td><?= e($log['admin_name'] ?? $log['user_email'] ?? 'Sistema') ?></td>
                        <td><?= e($actionLabels[$log['action']] ?? $log['action']) ?></td>
                        <td><?= e(($entityLabels[$log['entity_type']] ?? $log['entity_type'] ?? '—') . ($log['entity_id'] ? ' #' . $log['entity_id'] : '')) ?></td>
                        <td>
                            <?php if ($log['old_values'] !== null || $log['new_values'] !== null): ?>
                                <details class="audit-details">
                                    <summary>Ver</summary>
                                    <?php if ($log['old_values'] !== null): ?><p class="muted">Antes</p><pre><?= e($pretty($log['old_values'])) ?></pre><?php endif ?>
                                    <?php if ($log['new_values'] !== null): ?><p class="muted">Depois</p><pre><?= e($pretty($log['new_values'])) ?></pre><?php endif ?>
                                </details>
                            <?php endif ?>
                        </td>
                        <td class="nowrap"><?= e($log['ip_address'] ?? '—') ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?= $this->partial('partials/pagination', ['paginator' => $paginator, 'path' => '/admin/logs', 'params' => $params]) ?>
    <?php endif ?>
</section>
