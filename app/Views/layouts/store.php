<?php
/**
 * Layout da loja.
 * @var string $content
 * @var string|null $title
 * @var string|null $metaDescription
 * @var array|null $currentCustomer
 */
$title ??= config('app.name');
$currentCustomer ??= null;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <?php if (!empty($metaDescription)): ?>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <?php endif ?>
    <meta name="theme-color" content="#F5F2EC">
    <link rel="icon" href="<?= e(asset('img/logo-mark.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>

<header class="site-header">
    <div class="container site-header__inner">
        <a class="brand" href="<?= e(url('/')) ?>" aria-label="G-Nesting — página inicial">
            <img src="<?= e(asset('img/logo.svg')) ?>" alt="G-Nesting" width="190" height="35">
        </a>
        <nav class="site-nav" aria-label="Conta">
            <?php if ($currentCustomer !== null): ?>
                <a href="<?= e(url('/conta')) ?>">Olá, <?= e(explode(' ', $currentCustomer['name'])[0]) ?></a>
                <form method="post" action="<?= e(url('/sair')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="link-button">Sair</button>
                </form>
            <?php else: ?>
                <a href="<?= e(url('/entrar')) ?>">Entrar</a>
                <a class="btn btn--secondary btn--sm" href="<?= e(url('/cadastro')) ?>">Criar conta</a>
            <?php endif ?>
        </nav>
    </div>
</header>

<main id="conteudo">
    <?= $this->partial('partials/flash', ['flashSuccess' => $flashSuccess ?? null, 'flashError' => $flashError ?? null]) ?>
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container site-footer__inner">
        <div>
            <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="32" height="32">
            <p class="site-footer__tagline"><?= e(config('app.tagline')) ?></p>
        </div>
        <p class="site-footer__legal">© <?= e(date('Y')) ?> G-Nesting. Objetos produzidos com fabricação digital.</p>
    </div>
</footer>
</body>
</html>
