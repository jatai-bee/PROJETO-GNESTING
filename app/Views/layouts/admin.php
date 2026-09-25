<?php
/**
 * Layout do painel administrativo.
 * @var string $content
 * @var string|null $title
 * @var array{name:string,role:string,email:string} $currentAdmin
 */
use GNesting\Enums\AdminRole;

$title ??= 'Painel | G-Nesting';
$currentPath ??= '/admin';
$role = AdminRole::tryFrom($currentAdmin['role']);

// Módulos do painel. 'roles' = papéis que acessam (o proprietário acessa tudo).
// Itens sem 'href' são de etapas futuras: aparecem desabilitados.
$menu = [
    ['label' => 'Painel', 'href' => '/admin', 'roles' => null],
    ['label' => 'Pedidos', 'href' => '/admin/pedidos', 'roles' => ['manager', 'production', 'support']],
    ['label' => 'Clientes', 'href' => '/admin/clientes', 'roles' => ['manager', 'support']],
    ['label' => 'Produção', 'href' => '/admin/producao', 'roles' => ['manager', 'production']],
    ['label' => 'Expedição', 'href' => '/admin/expedicao', 'roles' => ['manager', 'production']],
    ['label' => 'Produtos', 'href' => '/admin/produtos', 'roles' => ['manager']],
    ['label' => 'Categorias', 'href' => '/admin/categorias', 'roles' => ['manager']],
    ['label' => 'Fichas de produção', 'href' => '/admin/fichas', 'roles' => ['manager', 'production']],
    ['label' => 'Materiais', 'href' => '/admin/materiais', 'roles' => ['manager', 'production']],
    ['label' => 'Cupons', 'href' => '/admin/cupons', 'roles' => ['manager']],
    ['label' => 'Configurações', 'href' => '/admin/configuracoes', 'roles' => ['owner']],
    ['label' => 'Usuários', 'href' => '/admin/usuarios', 'roles' => ['owner']],
    ['label' => 'Auditoria', 'href' => '/admin/logs', 'roles' => ['owner']],
];
$menu = array_filter($menu, static fn (array $item) => $item['roles'] === null || ($role?->isAllowed($item['roles']) ?? false));
$isCurrent = static fn (string $href): bool => $href === '/admin' ? $currentPath === '/admin' : str_starts_with($currentPath . '/', $href . '/');
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
    <nav aria-label="Módulos">
        <ul class="admin-menu">
            <?php foreach ($menu as $item): ?>
                <li>
                    <?php if ($item['href'] !== null): ?>
                        <a href="<?= e(url($item['href'])) ?>"<?= $isCurrent($item['href']) ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
                    <?php else: ?>
                        <span class="admin-menu__soon" title="Disponível na etapa <?= e($item['stage']) ?>">
                            <?= e($item['label']) ?> <small>etapa <?= e($item['stage']) ?></small>
                        </span>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    </nav>
</aside>

<div class="admin-main">
    <header class="admin-topbar">
        <div class="admin-topbar__user">
            <strong><?= e($currentAdmin['name']) ?></strong>
            <span class="badge"><?= e($role?->label() ?? $currentAdmin['role']) ?></span>
        </div>
        <form method="post" action="<?= e(url('/admin/sair')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--secondary btn--sm">Sair</button>
        </form>
    </header>

    <main id="conteudo" class="admin-content">
        <?= $this->partial('partials/flash', ['flashSuccess' => $flashSuccess ?? null, 'flashError' => $flashError ?? null]) ?>
        <?= $content ?>
    </main>
</div>
</body>
</html>
