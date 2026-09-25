<?php
/**
 * Ordem de produção: tudo o que a oficina precisa na máquina.
 * @var array<string, mixed>       $job
 * @var list<string>               $route
 * @var array<string, mixed>|null  $spec
 * @var list<array<string, mixed>> $steps
 * @var list<array<string, mixed>> $files
 * @var list<array<string, mixed>> $personalization
 * @var list<array<string, mixed>> $events
 * @var list<string>               $reworkTargets
 * @var string|null                $deadline
 */
use GNesting\Services\Production\ProductionFlow;

$open = !in_array($job['stage'], [ProductionFlow::DONE, ProductionFlow::CANCELLED], true);
$next = ProductionFlow::next($route, (string) $job['stage']);
$base = '/admin/producao/' . $job['id'];
$stepsByStage = [];
foreach ($steps as $step) {
    $stepsByStage[$step['stage']][] = $step;
}
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url('/admin/producao')) ?>">← Produção</a>
        <h1 class="page-title"><?= e($job['quantity']) ?> × <?= e($job['product_name']) ?></h1>
        <p class="muted"><?php if ($job['variant_name']): ?><?= e($job['variant_name']) ?> · <?php endif ?><code><?= e($job['sku']) ?></code> ·
            <a href="<?= e(url('/admin/pedidos/' . $job['order_id'])) ?>"><?= e($job['order_number']) ?></a> · <?= e($job['customer_name']) ?>
            <?php if ($deadline): ?>· prazo <strong><?= e(implode('/', array_reverse(explode('-', $deadline)))) ?></strong><?php endif ?></p>
    </div>
</div>

<ol class="route" aria-label="Rota de produção">
    <?php foreach ([ProductionFlow::QUEUED, ...$route, ProductionFlow::DONE] as $stage): ?>
        <?php $state = ProductionFlow::rank($stage) < ProductionFlow::rank((string) $job['stage']) ? 'done' : ($stage === $job['stage'] ? 'current' : 'todo'); ?>
        <li class="route__step route__step--<?= e($state) ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>><?= e(ProductionFlow::label($stage)) ?></li>
    <?php endforeach ?>
</ol>

<div class="order-admin">
    <div class="order-admin__main">
        <?php if ($personalization !== []): ?>
            <section class="panel panel--highlight">
                <h2 class="panel__title">Personalização (conferir antes de gravar)</h2>
                <?php foreach ($personalization as $choice): ?>
                    <p class="personalization-line personalization-line--big"><?= e($choice['label']) ?>:
                        <strong class="personalization-line__value"><?= e($choice['value_label'] ?? ($choice['type'] === 'date' ? implode('/', array_reverse(explode('-', (string) $choice['value_text']))) : $choice['value_text'])) ?></strong></p>
                <?php endforeach ?>
            </section>
        <?php endif ?>

        <section class="panel">
            <h2 class="panel__title">Ficha de produção</h2>
            <?php if ($spec === null): ?>
                <p class="muted">Esta variação não tem ficha. <a href="<?= e(url('/admin/produtos/' . $job['product_id'] . '/ficha-producao')) ?>">Criar ficha</a></p>
            <?php else: ?>
                <dl class="details">
                    <div><dt>Material</dt><dd><?= e(($spec['material_code'] ?? '—') . ' ' . ($spec['material_name'] ?? '')) ?> <?= $spec['thickness_mm'] !== null ? e(format_decimal($spec['thickness_mm']) . ' mm') : '' ?></dd></div>
                    <div><dt>Corte</dt><dd><?= $spec['cut_width_mm'] ? e($spec['cut_width_mm'] . ' × ' . $spec['cut_height_mm'] . ' mm') : '—' ?> · <?= e($spec['pieces_per_sheet'] ?? '—') ?> peça(s)/chapa</dd></div>
                    <div><dt>Programa CNC</dt><dd><code><?= e($spec['cnc_program_ref'] ?? '—') ?></code></dd></div>
                </dl>
                <?php foreach ($route as $stage): ?>
                    <?php if (!isset($stepsByStage[$stage])) { continue; } ?>
                    <h3 class="panel__subtitle<?= $stage === $job['stage'] ? ' text-accent' : '' ?>"><?= e(ProductionFlow::label($stage)) ?></h3>
                    <ul class="steps-list">
                        <?php foreach ($stepsByStage[$stage] as $step): ?>
                            <li><?= e($step['description'] ?? '') ?><?= $step['tool'] ? ' · ' . e($step['tool']) : '' ?>
                                <span class="muted">· <?= e(format_minutes((int) $step['estimated_minutes'])) ?>/un.<?= $step['is_passive'] ? ' (passiva)' : '' ?></span></li>
                        <?php endforeach ?>
                    </ul>
                <?php endforeach ?>
                <?php if (!empty($spec['finish_notes'])): ?><p><strong>Acabamento:</strong> <?= nl2br(e($spec['finish_notes']), false) ?></p><?php endif ?>
                <?php if (!empty($spec['internal_notes'])): ?><p><strong>Observações:</strong> <?= nl2br(e($spec['internal_notes']), false) ?></p><?php endif ?>
                <?php if ($files !== []): ?>
                    <h3 class="panel__subtitle">Arquivos</h3>
                    <ul class="steps-list">
                        <?php foreach ($files as $file): ?>
                            <li><a href="<?= e(url('/admin/arquivos-producao/' . $file['id'])) ?>"><?= e($file['original_name']) ?></a> v<?= e($file['version']) ?>
                                <code class="muted" title="SHA-256"><?= e(substr($file['checksum_sha256'], 0, 12)) ?>…</code></li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            <?php endif ?>
        </section>

        <section class="panel">
            <h2 class="panel__title">Tempos reais</h2>
            <ol class="timeline-admin">
                <?php foreach ($events as $i => $event): ?>
                    <?php
                    $nextAt = $events[$i + 1]['created_at'] ?? null;
                    $spent = $nextAt === null ? null : (int) round((strtotime($nextAt) - strtotime($event['created_at'])) / 60);
                    ?>
                    <li><strong><?= e(ProductionFlow::label((string) $event['to_stage'])) ?></strong>
                        <?php if ($event['is_rework']): ?><span class="status status--warn">retrabalho</span><?php endif ?>
                        <small class="muted"><?= e(format_datetime($event['created_at'])) ?> · <?= e($event['user_name'] ?? 'sistema') ?><?= $spent !== null ? ' · ' . e(format_minutes($spent)) . ' nesta etapa' : '' ?></small>
                        <?php if ($event['note']): ?><br><small><?= e($event['note']) ?></small><?php endif ?></li>
                <?php endforeach ?>
            </ol>
        </section>
    </div>

    <aside class="order-admin__side">
        <?php if ($open): ?>
            <section class="panel">
                <h2 class="panel__title"><?= e(ProductionFlow::label((string) $job['stage'])) ?></h2>
                <p class="muted">Responsável: <?= e($job['operator_name'] ?? 'ninguém') ?></p>
                <form method="post" action="<?= e(url($base . '/avancar')) ?>" class="step-form">
                    <?= csrf_field() ?>
                    <div class="field"><label for="note">Observação <span class="field__optional">(opcional)</span></label><input id="note" name="note" maxlength="500"></div>
                    <button type="submit" class="btn btn--primary btn--block"><?= e(ProductionFlow::actionLabel((string) $job['stage'], $next)) ?></button>
                </form>
                <form method="post" action="<?= e(url($base . '/assumir')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="link-button">Assumir esta ordem</button>
                </form>
            </section>

            <?php if ($reworkTargets !== []): ?>
                <section class="panel" id="retrabalho">
                    <h2 class="panel__title">Reprovar no controle de qualidade</h2>
                    <form method="post" action="<?= e(url($base . '/retrabalho')) ?>" class="step-form">
                        <?= csrf_field() ?>
                        <div class="field"><label for="to_stage">Voltar para</label>
                            <select id="to_stage" name="to_stage">
                                <?php foreach ($reworkTargets as $target): ?>
                                    <option value="<?= e($target) ?>"><?= e(ProductionFlow::label($target)) ?></option>
                                <?php endforeach ?>
                            </select></div>
                        <div class="field"><label for="rework-note">Motivo</label><input id="rework-note" name="note" maxlength="500" required></div>
                        <button type="submit" class="btn btn--danger btn--block">Enviar para retrabalho</button>
                    </form>
                </section>
            <?php endif ?>
        <?php else: ?>
            <section class="panel"><p><strong><?= e(ProductionFlow::label((string) $job['stage'])) ?></strong>
                <?php if ($job['finished_at']): ?><br><span class="muted">Concluída em <?= e(format_datetime($job['finished_at'])) ?></span><?php endif ?></p></section>
        <?php endif ?>
    </aside>
</div>
