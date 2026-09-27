<?php
/**
 * Página "Sem conexão" do aplicativo. Usa só os arquivos que o service worker guarda na instalação
 * (CSS e símbolo versionados): sem rede, nada mais carregaria. Sem style inline (a CSP bloqueia).
 * @var string $title
 */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#F6F3EE">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/store.css')) ?>">
</head>
<body class="store">
<main id="conteudo" class="offline-page">
    <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="64" height="64">
    <h1>Sem conexão</h1>
    <p>Não foi possível carregar esta página. Confira a internet do celular e tente de novo: o seu carrinho continua guardado.</p>
    <a class="btn btn--primary" href="<?= e(url('/')) ?>">Tentar de novo</a>
</main>
</body>
</html>
