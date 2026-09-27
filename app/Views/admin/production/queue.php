<?php
/**
 * @var list<array<string, mixed>> $jobs  jobs visíveis (com deadline, forecast, late, at_risk, remaining_minutes)
 * @var array{backlog_minutes: int, backlog_days: float, late: int, at_risk: int} $plan
 * @var string $stage filtro atual ('' = todas)
 * @var bool $mine
 * @var bool $board  true = quadro com uma coluna por etapa
 * @var array<string, int> $counts
 * @var array<int, list<array<string, mixed>>> $personalizations por order_item_id
 * @var int $capacity
 * @var int|null $userId
 */
use GNesting\Services\Production\ProductionFlow;

$date = static fn (?string $ymd): string => $ymd === null ? '—' : implode('/', array_reverse(explode('-', substr($ymd, 5)))); // dd/mm
$tabs = ['' => ['Todas', array_sum($counts)], ProductionFlow::QUEUED => ['Na fila', $counts[ProductionFlow::QUEUED] ?? 0]];
foreach (ProductionFlow::CANONICAL as $s) {
    $tabs[$s] = [ProductionFlow::label($s), $counts[$s] ?? 0];
}
$back = query_url('/admin/producao', ['etapa' => $stage, 'meus' => $mine ? '1' : null]);
?>
<div class="page-header">
    <h1 class="page-title">Produção</h1>
    <div class="page-header__actions">
        <nav class="segmented" aria-label="Visualização">
            <a href="<?= e(query_url('/admin/producao', ['etapa' => $stage, 'meus' => $mine ? '1' : null])) ?>"<?= !$board ? ' aria-current="page"' : '' ?>>Cartões</a>
            <a href="<?= e(query_url('/admin/producao', ['visao' => 'quadro', 'meus' => $mine ? '1' : null])) ?>"<?= $board ? ' aria-current="page"' : '' ?>>Quadro por etapa</a>
        </nav>
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/expedicao')) ?>">Expedição</a>
    </div>
</div>

<section class="stats" aria-label="Carga da produção">
    <div class="stat"><span class="stat__label">Carga na fila</span><span class="stat__value"><?= e(format_minutes($plan['backlog_minutes'])) ?></span>
        <span class="stat__meta">≈ <?= e(format_decimal(number_format($plan['backlog_days'], 1, '.', ''))) ?> dia(s) úteis a <?= e(format_minutes($capacity)) ?>/dia</span></div>
    <div class="stat"><span class="stat__label">Atrasados</span><span class="stat__value<?= $plan['late'] > 0 ? ' text-danger' : '' ?>"><?= e($plan['late']) ?></span>
        <span class="stat__meta">prazo prometido já passou</span></div>
    <div class="stat"><span class="stat__label">Em risco</span><span class="stat__value"><?= e($plan['at_risk']) ?></span>
        <span class="stat__meta">previsão depois do prazo</span></div>
</section>

<?php if ($board): ?>
    <?php
    $columns = [ProductionFlow::QUEUED => []];
    foreach (ProductionFlow::CANONICAL as $s) {
        $columns[$s] = [];
    }
    foreach ($jobs as $job) {
        $columns[(string) $job['stage']][] = $job;
    }
    ?>
    <p class="muted board-hint"><a href="<?= e(query_url('/admin/producao', ['visao' => 'quadro', 'meus' => $mine ? null : '1'])) ?>"><?= $mine ? 'Mostrar todas as ordens' : 'Só as minhas' ?></a> · clique numa ordem para ver a ficha e avançar.</p>
    <div class="board" role="list">
        <?php foreach ($columns as $column => $items): ?>
            <section class="board__col" role="listitem" aria-label="<?= e(ProductionFlow::label($column)) ?>">
                <header class="board__head"><?= e(ProductionFlow::label($column)) ?> <span><?= e(count($items)) ?></span></header>
                <?php foreach ($items as $job): ?>
                    <a class="board__card<?= $job['late'] ? ' board__card--late' : ($job['at_risk'] ? ' board__card--risk' : '') ?>" href="<?= e(url('/admin/producao/' . $job['id'])) ?>">
                        <span class="board__order mono"><?= e($job['order_number']) ?></span>
                        <strong><?= e($job['quantity']) ?> × <?= e($job['product_name']) ?></strong>
                        <?php if (!empty($personalizations[(int) $job['order_item_id']])): ?><span class="board__tag">personalizado</span><?php endif ?>
                        <span class="board__meta"><span class="<?= $job['late'] ? 'text-danger' : '' ?>">prazo <?= e($date($job['deadline'])) ?></span> · <?= e($job['operator_name'] ?? 'sem responsável') ?></span>
                    </a>
                <?php endforeach ?>
                <?php if ($items === []): ?><p class="board__empty">—</p><?php endif ?>
            </section>
        <?php endforeach ?>
    </div>
<?php else: ?>
<nav class="status-tabs" aria-label="Etapas">
    <?php foreach ($tabs as $value => [$label, $count]): ?>
        <a href="<?= e(query_url('/admin/producao', ['etapa' => $value, 'meus' => $mine ? '1' : null])) ?>"<?= $stage === $value ? ' aria-current="page"' : '' ?>>
            <?= e($label) ?> <span><?= e($count) ?></span>
        </a>
    <?php endforeach ?>
    <a href="<?= e(query_url('/admin/producao', ['etapa' => $stage, 'meus' => $mine ? null : '1'])) ?>"<?= $mine ? ' aria-current="page"' : '' ?>>Só os meus</a>
</nav>

<?php if ($jobs === []): ?>
    <section class="panel"><p class="muted">Nada nesta etapa.</p></section>
<?php else: ?>
    <div class="job-cards">
        <?php foreach ($jobs as $job): ?>
            <?php
            $next = ProductionFlow::next($job['route_list'], (string) $job['stage']);
            $choices = $personalizations[(int) $job['order_item_id']] ?? [];
            ?>
            <article class="job-card<?= $job['late'] ? ' job-card--late' : ($job['at_risk'] ? ' job-card--risk' : '') ?>">
                <header class="job-card__head">
                    <span class="job-card__stage"><?= e(ProductionFlow::label((string) $job['stage'])) ?></span>
                    <a href="<?= e(url('/admin/producao/' . $job['id'])) ?>" class="job-card__order"><?= e($job['order_number']) ?></a>
                </header>
                <p class="job-card__product"><strong><?= e($job['quantity']) ?> × <?= e($job['product_name']) ?></strong>
                    <?php if ($job['variant_name']): ?><br><span class="muted"><?= e($job['variant_name']) ?></span><?php endif ?></p>
                <?php foreach ($choices as $choice): ?>
                    <p class="personalization-line"><?= e($choice['label']) ?>:
                        <strong class="personalization-line__value"><?= e($choice['value_label'] ?? ($choice['type'] === 'date' ? implode('/', array_reverse(explode('-', (string) $choice['value_text']))) : $choice['value_text'])) ?></strong></p>
                <?php endforeach ?>
                <dl class="job-card__meta">
                    <div><dt>Prazo</dt><dd class="<?= $job['late'] ? 'text-danger' : '' ?>"><?= e($date($job['deadline'])) ?></dd></div>
                    <div><dt>Previsão</dt><dd><?= e($date($job['forecast'])) ?><?= $job['at_risk'] ? ' ⚠' : '' ?></dd></div>
                    <div><dt>Falta</dt><dd><?= e(format_minutes((int) $job['remaining_minutes'])) ?></dd></div>
                    <div><dt>Quem</dt><dd><?= e($job['operator_name'] ?? '—') ?></dd></div>
                </dl>
                <?php if ((int) $job['rework_count'] > 0): ?><p class="status status--warn">Retrabalho ×<?= e($job['rework_count']) ?></p><?php endif ?>

                <div class="job-card__actions">
                    <form method="post" action="<?= e(url('/admin/producao/' . $job['id'] . '/avancar')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="back" value="<?= e($back) ?>">
                        <button type="submit" class="btn btn--primary btn--block">
                            <?= e(ProductionFlow::actionLabel((string) $job['stage'], $next)) ?>
                        </button>
                    </form>
                    <?php if ((int) $job['operator_user_id'] !== (int) $userId): ?>
                        <form method="post" action="<?= e(url('/admin/producao/' . $job['id'] . '/assumir')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="back" value="<?= e($back) ?>">
                            <button type="submit" class="link-button">Assumir</button>
                        </form>
                    <?php endif ?>
                    <?php if ($job['stage'] === 'quality'): ?>
                        <a href="<?= e(url('/admin/producao/' . $job['id'])) ?>#retrabalho">Reprovar…</a>
                    <?php endif ?>
                </div>
            </article>
        <?php endforeach ?>
    </div>
<?php endif ?>
<?php endif ?>
