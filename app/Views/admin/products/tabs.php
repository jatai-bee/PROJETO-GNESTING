<?php
/**
 * Abas do produto. Geral, Comercial, Estoque e envio e SEO são partes do mesmo formulário (troca sem recarregar,
 * um único "Salvar"); Variações, Imagens, Personalização e Produção são telas próprias.
 * Só aparecem as abas que o papel atual pode abrir (o papel "produção" vê apenas a ficha de produção).
 * @var array<string, mixed>|null $product  null = produto novo (só as abas do formulário)
 * @var string   $active       geral | comercial | estoque | variantes | imagens | personalizacao | ficha | seo
 * @var int|null $imageCount
 * @var int|null $variantCount
 * @var int|null $ruleCount
 * @var array{role: string}|null $currentAdmin compartilhado pelo middleware
 */
use GNesting\Enums\AdminRole;

$base = $product !== null ? "/admin/produtos/{$product['id']}" : null;
$onForm = in_array($active, ['geral', 'comercial', 'estoque', 'seo'], true);
$formTab = static fn (string $key): string => $onForm ? '#' . $key : url("{$base}/editar") . '#' . $key;
$role = AdminRole::tryFrom((string) ($currentAdmin['role'] ?? ''));
$tabs = [
    'geral' => ['Geral', $formTab('geral'), null, ['manager'], true],
    'comercial' => ['Comercial', $formTab('comercial'), null, ['manager'], true],
    'estoque' => ['Estoque e envio', $formTab('estoque'), null, ['manager'], true],
    'variantes' => ['Variações', $base ? url("{$base}/variantes") : null, $variantCount ?? null, ['manager'], false],
    'imagens' => ['Imagens', $base ? url("{$base}/imagens") : null, $imageCount ?? null, ['manager'], false],
    'personalizacao' => ['Personalização', $base ? url("{$base}/personalizacao") : null, $ruleCount ?? null, ['manager'], false],
    'ficha' => ['Produção', $base ? url("{$base}/ficha-producao") : null, null, ['manager', 'production'], false],
    'seo' => ['SEO', $formTab('seo'), null, ['manager'], true],
];
?>
<nav class="tabs product-tabs" aria-label="Seções do produto">
    <?php foreach ($tabs as $key => [$label, $href, $count, $roles, $inForm]): ?>
        <?php if ($href === null || ($role !== null && !$role->isAllowed($roles))) { continue; } ?>
        <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?><?= $inForm && $onForm ? ' data-tab-link="' . e($key) . '"' : '' ?>><?= e($label) ?><?= $count !== null ? ' <small>' . e($count) . '</small>' : '' ?></a>
    <?php endforeach ?>
</nav>
