<?php
/**
 * Ordem de produção impressa (A4): vai para a bancada com a peça. Etapas com caixa para marcar,
 * personalização em destaque para conferir antes de gravar e espaço para anotar o tempo real.
 * @var array<string, mixed>       $job
 * @var list<string>               $route
 * @var array<string, mixed>|null  $spec
 * @var list<array<string, mixed>> $steps
 * @var list<array<string, mixed>> $personalization
 * @var string|null                $deadline
 */
use GNesting\Services\Production\ProductionFlow;

$stepsByStage = [];
foreach ($steps as $step) {
    $stepsByStage[$step['stage']][] = $step;
}
$dmy = static fn (?string $ymd): string => $ymd === null ? '—' : implode('/', array_reverse(explode('-', substr($ymd, 0, 10))));
?>
<div class="slip work-order">
    <header class="slip__head">
        <img src="<?= e(asset('img/logo.svg')) ?>" alt="G-Nesting" width="160" height="30">
        <div><strong>Ordem de produção</strong><br><?= e($job['order_number']) ?> · #<?= e($job['id']) ?></div>
    </header>

    <section class="work-order__title">
        <p class="slip__label">Peça</p>
        <p class="slip__recipient"><?= e($job['quantity']) ?> × <?= e($job['product_name']) ?></p>
        <p><?php if ($job['variant_name']): ?><?= e($job['variant_name']) ?> · <?php endif ?>SKU <strong class="mono"><?= e($job['sku']) ?></strong></p>
        <p>Cliente: <?= e($job['customer_name']) ?> · Prazo de produção: <strong><?= e($dmy($deadline)) ?></strong></p>
    </section>

    <?php if ($personalization !== []): ?>
        <section class="work-order__custom">
            <p class="slip__label">Personalização: conferir letra por letra antes de gravar</p>
            <?php foreach ($personalization as $choice): ?>
                <p><?= e($choice['label']) ?>: <strong><?= e($choice['value_label'] ?? ($choice['type'] === 'date' ? $dmy((string) $choice['value_text']) : $choice['value_text'])) ?></strong></p>
            <?php endforeach ?>
        </section>
    <?php endif ?>

    <?php if ($spec !== null): ?>
        <table class="slip__items">
            <tbody>
                <tr><th>Material</th><td><?= e(trim(($spec['material_code'] ?? '') . ' ' . ($spec['material_name'] ?? ''))) ?: '—' ?><?= $spec['thickness_mm'] !== null ? ' · ' . e(format_decimal($spec['thickness_mm'])) . ' mm' : '' ?></td></tr>
                <tr><th>Corte</th><td><?= $spec['cut_width_mm'] ? e($spec['cut_width_mm'] . ' × ' . $spec['cut_height_mm'] . ' mm') : '—' ?> · <?= e($spec['pieces_per_sheet'] ?? '—') ?> peça(s) por chapa</td></tr>
                <tr><th>Programa CNC</th><td class="mono"><?= e($spec['cnc_program_ref'] ?? '—') ?></td></tr>
            </tbody>
        </table>
    <?php else: ?>
        <p><strong>Sem ficha de produção cadastrada para esta variação.</strong></p>
    <?php endif ?>

    <table class="slip__items">
        <thead><tr><th>Feito</th><th>Etapa</th><th>O que fazer</th><th>Tempo real</th><th>Quem</th></tr></thead>
        <tbody>
        <?php foreach ($route as $stage): ?>
            <tr>
                <td class="slip__check">☐</td>
                <td><strong><?= e(ProductionFlow::label($stage)) ?></strong></td>
                <td>
                    <?php foreach ($stepsByStage[$stage] ?? [] as $step): ?>
                        <?= e($step['description'] ?? '') ?><?= $step['tool'] ? ' · ' . e($step['tool']) : '' ?> <span class="muted">(<?= e(format_minutes((int) $step['estimated_minutes'])) ?>/un.)</span><br>
                    <?php endforeach ?>
                </td>
                <td class="work-order__blank"></td>
                <td class="work-order__blank"></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>

    <?php if ($spec !== null && (!empty($spec['finish_notes']) || !empty($spec['internal_notes']))): ?>
        <section>
            <?php if (!empty($spec['finish_notes'])): ?><p><strong>Acabamento:</strong> <?= nl2br(e($spec['finish_notes']), false) ?></p><?php endif ?>
            <?php if (!empty($spec['internal_notes'])): ?><p><strong>Observações:</strong> <?= nl2br(e($spec['internal_notes']), false) ?></p><?php endif ?>
        </section>
    <?php endif ?>

    <p class="muted">Impresso em <?= e(format_datetime(now_utc())) ?>. Ao terminar cada etapa, avance a ordem no painel (Produção).</p>
    <p class="no-print"><button type="button" class="btn btn--primary" data-print>Imprimir</button>
        <a class="btn btn--secondary" href="<?= e(url('/admin/producao/' . $job['id'])) ?>">Voltar à ordem</a></p>
</div>
