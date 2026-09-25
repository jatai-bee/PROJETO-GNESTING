<?php
/**
 * Layout da loja.
 * @var string $content
 * @var string|null $title
 * @var string|null $metaDescription
 * @var string|null $canonical      URL absoluta canônica
 * @var bool|null   $noindex        páginas de busca, carrinho e listagens filtradas
 * @var array|null  $currentCustomer
 * @var int|null    $cartCount
 * @var list<array> $navCategories  árvore de categorias visíveis
 */
$title ??= config('app.name');
$currentCustomer ??= null;
$cartCount ??= 0;
$navCategories ??= [];
$searchQuery = isset($filters['q']) && is_string($filters['q']) ? $filters['q'] : '';
$robots = !empty($noindex) || !empty($hasFilters) ? 'noindex, follow' : null;
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
    <?php if (!empty($canonical)): ?>
    <link rel="canonical" href="<?= e($canonical) ?>">
    <?php endif ?>
    <?php if ($robots !== null): ?>
    <meta name="robots" content="<?= e($robots) ?>">
    <?php endif ?>
    <?php /* Compartilhamento (WhatsApp, redes sociais) */ ?>
    <meta property="og:site_name" content="G-Nesting">
    <meta property="og:locale" content="pt_BR">
    <meta property="og:type" content="<?= e($ogType ?? 'website') ?>">
    <meta property="og:title" content="<?= e($title) ?>">
    <?php if (!empty($metaDescription)): ?><meta property="og:description" content="<?= e($metaDescription) ?>"><?php endif ?>
    <?php if (!empty($canonical)): ?><meta property="og:url" content="<?= e($canonical) ?>"><?php endif ?>
    <meta property="og:image" content="<?= e($ogImage ?? absolute_url('/assets/img/og-default.png')) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <?php foreach ($jsonLd ?? [] as $structured): ?>
    <script type="application/ld+json"><?= \GNesting\Services\SeoData::encode($structured) ?></script>
    <?php endforeach ?>
    <meta name="theme-color" content="#F5F2EC">
    <link rel="icon" href="<?= e(asset('img/logo-mark.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/store.css')) ?>">
    <script src="<?= e(asset('js/store.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<?php if (!empty($announcement)): ?>
<p class="announcement"><?= e($announcement) ?></p>
<?php endif ?>

<header class="site-header">
    <div class="container site-header__inner">
        <a class="brand" href="<?= e(url('/')) ?>" aria-label="G-Nesting — página inicial">
            <img src="<?= e(asset('img/logo.svg')) ?>" alt="G-Nesting" width="190" height="35">
        </a>

        <form class="site-search" method="get" action="<?= e(url('/busca')) ?>" role="search">
            <label class="visually-hidden" for="busca-q">Buscar produtos</label>
            <input id="busca-q" type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="Buscar produtos" maxlength="80" autocomplete="off">
            <button type="submit" class="site-search__button">
                <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <span class="visually-hidden">Buscar</span>
            </button>
        </form>

        <nav class="site-nav" aria-label="Conta e carrinho">
            <?php if ($currentCustomer !== null): ?>
                <a href="<?= e(url('/conta')) ?>" class="site-nav__account">Olá, <?= e(explode(' ', $currentCustomer['name'])[0]) ?></a>
                <form method="post" action="<?= e(url('/sair')) ?>" class="inline-form site-nav__logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="link-button">Sair</button>
                </form>
            <?php else: ?>
                <a href="<?= e(url('/entrar')) ?>" class="site-nav__account">Entrar</a>
            <?php endif ?>
            <a class="cart-link" href="<?= e(url('/carrinho')) ?>">
                <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 8h14l-1.2 11.2a1 1 0 0 1-1 .8H7.2a1 1 0 0 1-1-.8L5 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
                <span class="visually-hidden">Carrinho,</span>
                <span class="cart-link__count" data-count="<?= e($cartCount) ?>"><?= e($cartCount) ?></span>
                <span class="visually-hidden"><?= $cartCount === 1 ? 'item' : 'itens' ?></span>
            </a>
        </nav>
    </div>

    <?php if ($navCategories !== []): ?>
    <nav class="category-nav" aria-label="Categorias">
        <div class="container">
            <ul class="category-nav__list">
                <li><a href="<?= e(url('/produtos')) ?>">Todos</a></li>
                <?php foreach ($navCategories as $navCategory): ?>
                    <?php if ($navCategory['total'] > 0): ?>
                    <li><a href="<?= e(url('/categoria/' . $navCategory['slug'])) ?>"><?= e($navCategory['name']) ?></a></li>
                    <?php endif ?>
                <?php endforeach ?>
            </ul>
        </div>
    </nav>
    <?php endif ?>
</header>

<main id="conteudo">
    <?= $this->partial('partials/flash', ['flashSuccess' => $flashSuccess ?? null, 'flashError' => $flashError ?? null]) ?>
    <?= $content ?>
</main>

<footer class="site-footer<?= !empty($floatingWhatsapp) ? ' site-footer--float-space' : '' ?>">
    <div class="container site-footer__inner">
        <div>
            <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="32" height="32">
            <p class="site-footer__tagline"><?= e(config('app.tagline')) ?></p>
        </div>
        <nav class="site-footer__links" aria-label="Institucional">
            <a href="<?= e(url('/sobre')) ?>">Sobre</a>
            <a href="<?= e(url('/como-fazemos')) ?>">Como fazemos</a>
            <a href="<?= e(url('/trocas-e-devolucoes')) ?>">Trocas e devoluções</a>
            <a href="<?= e(url('/privacidade')) ?>">Privacidade</a>
            <a href="<?= e(url('/termos')) ?>">Termos de uso</a>
        </nav>
        <p class="site-footer__legal">
            <?php if (!empty($contactEmail)): ?><a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a><br><?php endif ?>
            © <?= e(date('Y')) ?> G-Nesting. Objetos produzidos com fabricação digital.
        </p>
    </div>
</footer>
<?php if (!empty($floatingWhatsapp)): ?>
<a class="whatsapp-float" href="<?= e($floatingWhatsapp) ?>" target="_blank" rel="noopener noreferrer" aria-label="Fale conosco pelo WhatsApp">
    <svg aria-hidden="true" width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z"/></svg>
    <span class="visually-hidden">WhatsApp</span>
</a>
<?php endif ?>
</body>
</html>
