<?php
/**
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
?>
<nav class="breadcrumbs" aria-label="Você está em">
    <ol>
        <li><a href="<?= e(url('/')) ?>">Início</a></li>
        <?php foreach ($breadcrumbs as $crumb): ?>
            <li>
                <?php if ($crumb['url'] !== null): ?>
                    <a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
                <?php else: ?>
                    <span aria-current="page"><?= e($crumb['label']) ?></span>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
</nav>
