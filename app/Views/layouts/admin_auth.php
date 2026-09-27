<?php
/**
 * Layout mínimo para o login do painel.
 * @var string $content
 * @var string|null $title
 */
$title ??= 'Painel | G-Nesting';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?></title>
    <link rel="icon" href="<?= e(asset('img/logo-mark.svg')) ?>" type="image/svg+xml">
    <?php /* Aplicativo do painel no celular */ ?>
    <link rel="manifest" href="<?= e(url('/admin/manifest.webmanifest')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('icons/painel-apple-180.png')) ?>">
    <meta name="theme-color" content="#1F1E1C">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Painel G-Nesting">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script src="<?= e(asset('js/admin.js')) ?>" defer></script>
</head>
<body class="admin-auth" data-sw="<?= e(url('/sw.js')) ?>">
<main id="conteudo">
    <?= $this->partial('partials/flash', ['flashSuccess' => $flashSuccess ?? null, 'flashError' => $flashError ?? null]) ?>
    <?= $content ?>
</main>
</body>
</html>
