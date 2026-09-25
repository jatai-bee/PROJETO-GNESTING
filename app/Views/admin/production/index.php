<?php
/**
 * @var list<array<string, mixed>> $rows uma linha por variação, com 'missing'
 * @var string $q
 * @var int $complete
 */
?>
<div class="page-header">
    <h1 class="page-title">Fichas de produção</h1>
</div>

<section class="panel">
    <p class="muted">Uma ficha por variação: material, corte, programa CNC, etapas com tempo e arquivos. Documento interno, nunca exibido na loja.</p>
    <form method="get" action="<?= e(url('/admin/fichas')) ?>" class="inline-add">
        <label class="visually-hidden" for="q">Buscar produto ou SKU</label>
        <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Produto ou SKU">
        <button type="submit" class="btn btn--secondary btn--sm">Buscar</button>
    </form>
    <p class="table-summary"><?= e($complete) ?> de <?= e(count($rows)) ?> variações com ficha completa.</p>

    <?php if ($rows === []): ?>
        <p class="muted">Nenhum produto encontrado.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Produto / variação</th><th>SKU</th><th>Material</th><th class="table__num">Tempo</th><th class="table__num">Arquivos</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $href = url("/admin/produtos/{$row['product_id']}/ficha-producao/{$row['variant_id']}"); ?>
                    <tr>
                        <td>
                            <a href="<?= e($href) ?>"><?= e($row['product_name']) ?></a>
                            <?php if ($row['variant_name'] !== null): ?><span class="muted">· <?= e($row['variant_name']) ?></span><?php endif ?>
                            <?php if (!$row['product_active'] || !$row['variant_active']): ?><span class="status status--off">Fora da loja</span><?php endif ?>
                        </td>
                        <td><code><?= e($row['sku']) ?></code></td>
                        <td><?= $row['material_code'] ? e($row['material_name'] . ' ' . format_decimal($row['material_thickness']) . ' mm') : '<span class="muted">—</span>' ?></td>
                        <td class="table__num nowrap"><?= (int) $row['total_minutes'] > 0 ? e(format_minutes((int) $row['total_minutes'])) : '—' ?></td>
                        <td class="table__num"><?= e($row['file_count']) ?></td>
                        <td>
                            <?php if ($row['missing'] === []): ?>
                                <span class="status status--on">Completa</span>
                            <?php elseif ($row['spec_id'] === null): ?>
                                <span class="status status--off">Sem ficha</span>
                            <?php else: ?>
                                <span class="status status--warn" title="Falta: <?= e(implode(', ', $row['missing'])) ?>">Incompleta</span>
                                <small class="muted">falta <?= e(implode(', ', $row['missing'])) ?></small>
                            <?php endif ?>
                        </td>
                        <td class="table__actions"><a class="btn btn--secondary btn--sm" href="<?= e($href) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>
