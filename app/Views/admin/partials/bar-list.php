<?php
/**
 * Lista com barra de proporção (mais vendidos, vendas por categoria).
 * @var list<array{label: string, value: int, display: string, meta?: string, href?: string|null}> $rows
 * @var string $empty texto quando não há dados
 */
$max = max([1, ...array_column($rows, 'value')]);
?>
<?php if ($rows === []): ?>
    <p class="muted"><?= e($empty) ?></p>
<?php else: ?>
    <ol class="bar-list">
        <?php foreach ($rows as $row): ?>
            <li>
                <div class="bar-list__head">
                    <?php if (!empty($row['href'])): ?><a href="<?= e($row['href']) ?>"><?= e($row['label']) ?></a><?php else: ?><span><?= e($row['label']) ?></span><?php endif ?>
                    <strong><?= e($row['display']) ?></strong>
                </div>
                <svg class="bar-list__bar" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true">
                    <rect x="0" y="0" width="100" height="6" class="bar-list__track"/>
                    <rect x="0" y="0" width="<?= round(max(1.5, $row['value'] / $max * 100), 2) ?>" height="6" class="bar-list__fill"/>
                </svg>
                <?php if (!empty($row['meta'])): ?><small class="muted"><?= e($row['meta']) ?></small><?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
<?php endif ?>
