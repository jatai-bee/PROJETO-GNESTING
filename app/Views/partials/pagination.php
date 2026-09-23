<?php
/**
 * @var \GNesting\Core\Paginator      $paginator
 * @var string                        $path
 * @var array<string, scalar|null>    $params filtros atuais (preservados nos links)
 */
if (!$paginator->hasPages()) {
    return;
}
$link = static fn (int $page): string => query_url($path, $params + ['pagina' => $page > 1 ? $page : null]);
$start = max(1, $paginator->page - 2);
$end = min($paginator->lastPage, $paginator->page + 2);
?>
<nav class="pagination" aria-label="Paginação">
    <?php if ($paginator->page > 1): ?>
        <a href="<?= e($link($paginator->page - 1)) ?>" rel="prev">← Anterior</a>
    <?php endif ?>
    <?php for ($p = $start; $p <= $end; $p++): ?>
        <?php if ($p === $paginator->page): ?>
            <span aria-current="page"><?= e($p) ?></span>
        <?php else: ?>
            <a href="<?= e($link($p)) ?>"><?= e($p) ?></a>
        <?php endif ?>
    <?php endfor ?>
    <?php if ($paginator->page < $paginator->lastPage): ?>
        <a href="<?= e($link($paginator->page + 1)) ?>" rel="next">Próxima →</a>
    <?php endif ?>
</nav>
