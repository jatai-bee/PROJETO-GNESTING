<?php
/**
 * Layout independente para páginas de erro (não depende de sessão nem de banco).
 * @var string $content
 * @var int $status
 */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($status) ?> | G-Nesting</title>
    <link rel="icon" href="<?= e(asset('img/logo-mark.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="error-page">
<main id="conteudo" class="container">
    <a href="<?= e(url('/')) ?>"><img src="<?= e(asset('img/logo.svg')) ?>" alt="G-Nesting" width="190" height="35"></a>
    <?= $content ?>
</main>
</body>
</html>
