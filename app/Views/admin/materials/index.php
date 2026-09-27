<?php
/** @var list<array<string, mixed>> $materials */
use GNesting\Services\MaterialService;

$units = MaterialService::UNITS;
?>
<div class="page-header">
    <h1 class="page-title">Matérias-primas</h1>
    <a class="btn btn--primary" href="<?= e(url('/admin/materiais/novo')) ?>">Nova matéria-prima</a>
</div>

<section class="panel">
    <p class="muted">Matéria-prima usada nas fichas de produção. Uso interno: nada daqui aparece na loja.</p>
    <?php if ($materials === []): ?>
        <p class="muted">Nenhum material cadastrado.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Código</th><th>Material</th><th class="table__num">Espessura</th><th>Chapa</th><th class="table__num">Custo</th><th class="table__num">Saldo</th><th>Fichas</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr>
                </thead>
                <tbody>
                <?php foreach ($materials as $m): ?>
                    <tr>
                        <td><code><?= e($m['code']) ?></code></td>
                        <td><a href="<?= e(url("/admin/materiais/{$m['id']}/editar")) ?>"><?= e($m['name']) ?></a></td>
                        <td class="table__num nowrap"><?= e(format_decimal($m['thickness_mm'])) ?> mm</td>
                        <td class="nowrap"><?= $m['sheet_width_mm'] ? e($m['sheet_width_mm'] . ' × ' . $m['sheet_length_mm'] . ' mm') : '—' ?></td>
                        <td class="table__num nowrap"><?= $m['cost_cents'] !== null ? e(money((int) $m['cost_cents']) . ' / ' . mb_strtolower($units[$m['unit']] ?? $m['unit'])) : '—' ?></td>
                        <td class="table__num nowrap">
                            <?= e(format_decimal($m['stock_qty'])) ?>
                            <?php if ($m['needs_reorder']): ?><span class="status status--warn">Repor</span><?php endif ?>
                        </td>
                        <td><?= e($m['spec_count']) ?></td>
                        <td><?= $m['is_active'] ? '<span class="status status--on">Ativo</span>' : '<span class="status status--off">Inativo</span>' ?></td>
                        <td class="table__actions">
                            <a class="btn btn--secondary btn--sm" href="<?= e(url("/admin/materiais/{$m['id']}/editar")) ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>
