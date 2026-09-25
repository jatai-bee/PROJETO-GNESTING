<?php
/**
 * Abas do produto no painel. Só aparecem as abas que o papel atual pode abrir
 * (o papel "produção" vê apenas a ficha de produção).
 * @var array<string, mixed> $product
 * @var string   $active       dados | imagens | variantes | personalizacao | ficha
 * @var int|null $imageCount
 * @var int|null $variantCount
 * @var int|null $ruleCount
 * @var array{role: string}|null $currentAdmin compartilhado pelo middleware
 */
use GNesting\Enums\AdminRole;

$base = "/admin/produtos/{$product['id']}";
$role = AdminRole::tryFrom((string) ($currentAdmin['role'] ?? ''));
$tabs = [
    'dados' => ['Dados', "{$base}/editar", null, ['manager']],
    'imagens' => ['Imagens', "{$base}/imagens", $imageCount ?? null, ['manager']],
    'variantes' => ['Variações', "{$base}/variantes", $variantCount ?? null, ['manager']],
    'personalizacao' => ['Personalização', "{$base}/personalizacao", $ruleCount ?? null, ['manager']],
    'ficha' => ['Ficha de produção', "{$base}/ficha-producao", null, ['manager', 'production']],
];
?>
<nav class="tabs" aria-label="Seções do produto">
    <?php foreach ($tabs as $key => [$label, $href, $count, $roles]): ?>
        <?php if ($role !== null && !$role->isAllowed($roles)) { continue; } ?>
        <a href="<?= e(url($href)) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?><?= $count !== null ? ' (' . e($count) . ')' : '' ?></a>
    <?php endforeach ?>
</nav>
