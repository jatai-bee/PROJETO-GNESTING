<?php
/**
 * Layout do painel: menu lateral agrupado por área (gaveta no celular), barra superior com a trilha
 * de navegação, atalho para a loja e o usuário.
 * @var string $content
 * @var string|null $title
 * @var array{name:string,role:string,email:string} $currentAdmin
 * @var string|null $currentPath
 * @var list<array{label: string, url: ?string}>|null $breadcrumbs trilha extra da página (depois do módulo)
 */
use GNesting\Enums\AdminRole;

$title ??= 'Painel | G-Nesting';
$currentPath ??= '/admin';
$role = AdminRole::tryFrom($currentAdmin['role']);

// Módulos do painel por área. 'roles' = papéis que acessam (o proprietário acessa tudo).
$groups = [
    ['label' => null, 'items' => [
        ['label' => 'Visão geral', 'href' => '/admin', 'icon' => 'home', 'roles' => null],
    ]],
    ['label' => 'Vendas', 'items' => [
        ['label' => 'Pedidos', 'href' => '/admin/pedidos', 'icon' => 'bag', 'roles' => ['manager', 'production', 'support']],
        ['label' => 'Clientes', 'href' => '/admin/clientes', 'icon' => 'users', 'roles' => ['manager', 'support']],
        ['label' => 'Cupons', 'href' => '/admin/cupons', 'icon' => 'ticket', 'roles' => ['manager']],
    ]],
    ['label' => 'Produção', 'items' => [
        ['label' => 'Fila de produção', 'href' => '/admin/producao', 'icon' => 'tool', 'roles' => ['manager', 'production']],
        ['label' => 'Expedição', 'href' => '/admin/expedicao', 'icon' => 'truck', 'roles' => ['manager', 'production']],
        ['label' => 'Fichas de produção', 'href' => '/admin/fichas', 'icon' => 'file', 'roles' => ['manager', 'production']],
        ['label' => 'Matérias-primas', 'href' => '/admin/materiais', 'icon' => 'layers', 'roles' => ['manager', 'production']],
    ]],
    ['label' => 'Catálogo', 'items' => [
        ['label' => 'Produtos', 'href' => '/admin/produtos', 'icon' => 'tag', 'roles' => ['manager']],
        ['label' => 'Categorias', 'href' => '/admin/categorias', 'icon' => 'grid', 'roles' => ['manager']],
    ]],
    ['label' => 'Administração', 'items' => [
        ['label' => 'Configurações', 'href' => '/admin/configuracoes', 'icon' => 'settings', 'roles' => ['owner']],
        ['label' => 'Usuários', 'href' => '/admin/usuarios', 'icon' => 'shield', 'roles' => ['owner']],
        ['label' => 'Auditoria', 'href' => '/admin/logs', 'icon' => 'list', 'roles' => ['owner']],
        ['label' => 'Sistema', 'href' => '/admin/sistema', 'icon' => 'server', 'roles' => ['owner']],
    ]],
];
$isCurrent = static fn (string $href): bool => $href === '/admin' ? $currentPath === '/admin' : str_starts_with($currentPath . '/', $href . '/');
$currentGroup = null;
$currentItem = null;
foreach ($groups as $g => $group) {
    $groups[$g]['items'] = array_values(array_filter(
        $group['items'],
        static fn (array $item): bool => $item['roles'] === null || ($role?->isAllowed($item['roles']) ?? false)
    ));
    foreach ($groups[$g]['items'] as $item) {
        if ($isCurrent($item['href'])) {
            $currentGroup = $group['label'];
            $currentItem = $item;
        }
    }
}
$groups = array_values(array_filter($groups, static fn (array $group): bool => $group['items'] !== []));

// Trilha: Painel › área › módulo › (página, quando não é a lista do módulo)
$trail = [];
if ($currentItem !== null && $currentItem['href'] !== '/admin') {
    if ($currentGroup !== null) {
        $trail[] = ['label' => $currentGroup, 'url' => null];
    }
    $atModuleRoot = $currentPath === $currentItem['href'];
    $trail[] = ['label' => $currentItem['label'], 'url' => $atModuleRoot && empty($breadcrumbs) ? null : url($currentItem['href'])];
    if (!empty($breadcrumbs)) {
        array_push($trail, ...$breadcrumbs);
    } elseif (!$atModuleRoot) {
        $pageTitle = trim(explode('|', $title)[0]);
        if ($pageTitle !== '' && $pageTitle !== $currentItem['label']) {
            $trail[] = ['label' => $pageTitle, 'url' => null];
        }
    }
}
$icon = fn (string $name, int $size = 18): string => $this->partial('partials/icon', ['name' => $name, 'size' => $size]);
$menu = function () use ($groups, $isCurrent, $icon): string {
    ob_start();
    foreach ($groups as $group): ?>
        <div class="admin-menu__group">
            <?php if ($group['label'] !== null): ?><p class="admin-menu__label"><?= e($group['label']) ?></p><?php endif ?>
            <ul class="admin-menu">
                <?php foreach ($group['items'] as $item): ?>
                    <li><a href="<?= e(url($item['href'])) ?>"<?= $isCurrent($item['href']) ? ' aria-current="page"' : '' ?>><?= $icon($item['icon']) ?><span><?= e($item['label']) ?></span></a></li>
                <?php endforeach ?>
            </ul>
        </div>
    <?php endforeach;

    return (string) ob_get_clean();
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?></title>
    <link rel="icon" href="<?= e(asset('img/logo-mark.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
    <script src="<?= e(asset('js/admin.js')) ?>" defer></script>
</head>
<body class="admin">
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>

<aside class="admin-sidebar">
    <a class="admin-sidebar__brand" href="<?= e(url('/admin')) ?>">
        <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="28" height="28">
        <span>G-Nesting <small>Painel</small></span>
    </a>
    <nav aria-label="Módulos"><?= $menu() ?></nav>
</aside>

<div class="admin-main">
    <header class="admin-topbar">
        <details class="admin-drawer">
            <summary class="admin-drawer__toggle" aria-label="Abrir menu"><?= $icon('menu', 22) ?></summary>
            <nav class="admin-drawer__panel" aria-label="Módulos">
                <a class="admin-sidebar__brand" href="<?= e(url('/admin')) ?>">
                    <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="28" height="28">
                    <span>G-Nesting <small>Painel</small></span>
                </a>
                <?= $menu() ?>
            </nav>
        </details>

        <nav class="admin-trail" aria-label="Você está em">
            <ol>
                <li><?php if ($trail === []): ?><span aria-current="page">Visão geral</span><?php else: ?><a href="<?= e(url('/admin')) ?>">Painel</a><?php endif ?></li>
                <?php foreach ($trail as $n => $crumb): ?>
                    <li><?php if ($crumb['url'] !== null): ?><a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a><?php else: ?><span<?= $n === array_key_last($trail) ? ' aria-current="page"' : '' ?>><?= e($crumb['label']) ?></span><?php endif ?></li>
                <?php endforeach ?>
            </ol>
        </nav>

        <div class="admin-topbar__actions">
            <a class="btn btn--ghost btn--sm admin-topbar__store" href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= $icon('external', 16) ?> <span>Ver loja</span></a>
            <div class="admin-topbar__user">
                <span class="admin-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($currentAdmin['name'], 0, 1))) ?></span>
                <span class="admin-topbar__who"><strong><?= e($currentAdmin['name']) ?></strong><small><?= e($role?->label() ?? $currentAdmin['role']) ?></small></span>
            </div>
            <form method="post" action="<?= e(url('/admin/sair')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--secondary btn--sm">Sair</button>
            </form>
        </div>
    </header>

    <main id="conteudo" class="admin-content">
        <?= $this->partial('partials/flash', ['flashSuccess' => $flashSuccess ?? null, 'flashError' => $flashError ?? null]) ?>
        <?= $content ?>
    </main>
</div>
</body>
</html>
