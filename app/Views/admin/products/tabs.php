<?php
/**
 * Abas do produto no painel.
 * @var array<string, mixed> $product
 * @var string   $active       dados | imagens | variantes | personalizacao
 * @var int|null $imageCount
 * @var int|null $variantCount
 * @var int|null $ruleCount
 */
$base = "/admin/produtos/{$product['id']}";
$tabs = [
    'dados' => ['Dados', "{$base}/editar", null],
    'imagens' => ['Imagens', "{$base}/imagens", $imageCount ?? null],
    'variantes' => ['Variações', "{$base}/variantes", $variantCount ?? null],
    'personalizacao' => ['Personalização', "{$base}/personalizacao", $ruleCount ?? null],
];
?>
<nav class="tabs" aria-label="Seções do produto">
    <?php foreach ($tabs as $key => [$label, $href, $count]): ?>
        <a href="<?= e(url($href)) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?><?= $count !== null ? ' (' . e($count) . ')' : '' ?></a>
    <?php endforeach ?>
    <span class="tabs__soon" title="Etapa 6">Ficha de produção</span>
</nav>
