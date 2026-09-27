<?php
/**
 * Layout da loja: faixa de avisos, cabeçalho fixo (marca, busca, favoritos, conta, carrinho),
 * menu de categorias (suspenso no computador, gaveta no celular) e rodapé completo.
 * @var string $content
 * @var string|null $title
 * @var string|null $metaDescription
 * @var string|null $canonical      URL absoluta canônica
 * @var bool|null   $noindex        páginas de busca, carrinho e listagens filtradas
 * @var array|null  $currentCustomer
 * @var int|null    $cartCount
 * @var list<int>|null $favoriteIds
 * @var list<array> $navCategories  árvore de categorias visíveis (com 'children' e 'total')
 */
$title ??= config('app.name');
$currentCustomer ??= null;
$cartCount ??= 0;
$favoriteIds ??= [];
$navCategories = array_values(array_filter($navCategories ?? [], static fn (array $c): bool => (int) $c['total'] > 0));
$searchQuery = isset($filters['q']) && is_string($filters['q']) ? $filters['q'] : '';
$robots = !empty($noindex) || !empty($hasFilters) ? 'noindex, follow' : null;
$currentPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$favCount = count($favoriteIds);
$firstName = $currentCustomer !== null ? explode(' ', trim((string) $currentCustomer['name']))[0] : null;
$icon = fn (string $name, int $size = 20): string => $this->partial('partials/icon', ['name' => $name, 'size' => $size]);
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
    <meta name="theme-color" content="#F6F3EE">
    <link rel="icon" href="<?= e(asset('img/logo-mark.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(asset('fonts/manrope-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/store.css')) ?>">
    <script src="<?= e(asset('js/store.js')) ?>" defer></script>
</head>
<body class="store">
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<?php if (!empty($announcement)): ?>
<p class="announcement"><?= e($announcement) ?></p>
<?php endif ?>

<header class="site-header">
    <div class="container header-main">
        <details class="nav-drawer">
            <summary class="header-action" aria-label="Abrir menu">
                <?= $icon('menu', 24) ?>
            </summary>
            <nav class="nav-drawer__panel" aria-label="Menu">
                <div class="nav-drawer__head">
                    <img src="<?= e(asset('img/logo.svg')) ?>" alt="G-Nesting" width="130" height="24">
                </div>
                <ul class="nav-drawer__list">
                    <li><a href="<?= e(url('/produtos')) ?>">Todos os produtos</a></li>
                    <?php foreach ($navCategories as $navCategory): ?>
                    <li>
                        <?php if ($navCategory['children'] !== []): ?>
                        <details>
                            <summary><?= e($navCategory['name']) ?></summary>
                            <ul>
                                <li><a href="<?= e(url('/categoria/' . $navCategory['slug'])) ?>">Ver tudo em <?= e($navCategory['name']) ?></a></li>
                                <?php foreach ($navCategory['children'] as $child): ?>
                                    <?php if ((int) $child['product_count'] > 0): ?>
                                    <li><a href="<?= e(url('/categoria/' . $child['slug'])) ?>"><?= e($child['name']) ?></a></li>
                                    <?php endif ?>
                                <?php endforeach ?>
                            </ul>
                        </details>
                        <?php else: ?>
                        <a href="<?= e(url('/categoria/' . $navCategory['slug'])) ?>"><?= e($navCategory['name']) ?></a>
                        <?php endif ?>
                    </li>
                    <?php endforeach ?>
                    <li><a href="<?= e(url('/produtos?oferta=1')) ?>">Ofertas</a></li>
                    <li><a href="<?= e(url('/produtos?ordem=novidades')) ?>">Novidades</a></li>
                    <li><a href="<?= e(url('/produtos?pronta=1')) ?>">Pronta entrega</a></li>
                </ul>
                <div class="nav-drawer__extra">
                    <?php if ($currentCustomer !== null): ?>
                        <a class="btn btn--secondary btn--block" href="<?= e(url('/conta')) ?>">Minha conta</a>
                        <form method="post" action="<?= e(url('/sair')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn--ghost btn--block">Sair</button>
                        </form>
                    <?php else: ?>
                        <a class="btn btn--dark btn--block" href="<?= e(url('/entrar')) ?>">Entrar</a>
                        <a class="btn btn--secondary btn--block" href="<?= e(url('/cadastro')) ?>">Criar conta</a>
                    <?php endif ?>
                    <a class="btn btn--ghost btn--block" href="<?= e(url('/como-fazemos')) ?>">Como fazemos</a>
                </div>
            </nav>
        </details>

        <a class="brand" href="<?= e(url('/')) ?>" aria-label="G-Nesting — página inicial">
            <img src="<?= e(asset('img/logo.svg')) ?>" alt="G-Nesting" width="190" height="35">
        </a>

        <form class="site-search" method="get" action="<?= e(url('/busca')) ?>" role="search">
            <label class="visually-hidden" for="busca-q">Buscar produtos</label>
            <input id="busca-q" type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="O que você procura? Ex.: relógio, organizador, nicho" maxlength="80" autocomplete="off">
            <button type="submit" class="site-search__button">
                <?= $icon('search', 18) ?>
                <span class="visually-hidden">Buscar</span>
            </button>
        </form>

        <nav class="header-actions" aria-label="Favoritos, conta e carrinho">
            <a class="header-action" href="<?= e(url('/conta/favoritos')) ?>">
                <?= $icon('heart', 22) ?>
                <span class="header-action__label">Favoritos</span>
                <?php if ($favCount > 0): ?><span class="header-action__count"><?= e($favCount) ?><span class="visually-hidden"> produtos</span></span><?php endif ?>
            </a>
            <a class="header-action" href="<?= e(url($currentCustomer !== null ? '/conta' : '/entrar')) ?>">
                <?= $icon('user', 22) ?>
                <span class="header-action__label"><?= $firstName !== null ? 'Olá, ' . e($firstName) : 'Entrar' ?></span>
                <?php if ($firstName === null): ?><span class="visually-hidden">na sua conta</span><?php endif ?>
            </a>
            <a class="header-action" href="<?= e(url('/carrinho')) ?>">
                <?= $icon('bag', 22) ?>
                <span class="header-action__label">Carrinho</span>
                <span class="header-action__count" data-count="<?= e($cartCount) ?>"><?= e($cartCount) ?><span class="visually-hidden"> <?= $cartCount === 1 ? 'item' : 'itens' ?></span></span>
            </a>
        </nav>
    </div>

    <?php if ($navCategories !== []): ?>
    <nav class="nav-main" aria-label="Categorias">
        <div class="container nav-main__inner">
            <ul class="nav-main__list">
                <li><a class="nav-main__link" href="<?= e(url('/produtos')) ?>"<?= $currentPath === '/produtos' ? ' aria-current="page"' : '' ?>>Todos</a></li>
                <?php foreach ($navCategories as $navCategory): ?>
                    <?php $catHref = url('/categoria/' . $navCategory['slug']); $hasChildren = $navCategory['children'] !== []; ?>
                <li>
                    <a class="nav-main__link<?= $hasChildren ? ' nav-main__link--caret' : '' ?>" href="<?= e($catHref) ?>"<?= $currentPath === '/categoria/' . $navCategory['slug'] ? ' aria-current="page"' : '' ?>><?= e($navCategory['name']) ?></a>
                    <?php if ($hasChildren): ?>
                    <div class="mega">
                        <div>
                            <p class="mega__title"><?= e($navCategory['name']) ?></p>
                            <ul class="mega__list">
                                <?php foreach ($navCategory['children'] as $child): ?>
                                    <?php if ((int) $child['product_count'] > 0): ?>
                                    <li><a href="<?= e(url('/categoria/' . $child['slug'])) ?>"><?= e($child['name']) ?></a></li>
                                    <?php endif ?>
                                <?php endforeach ?>
                            </ul>
                            <a class="link-arrow mega__all" href="<?= e($catHref) ?>">Ver todos (<?= e($navCategory['total']) ?>)</a>
                        </div>
                        <?php if (!empty($navCategory['image_path'])): ?>
                        <a class="mega__image" href="<?= e($catHref) ?>" tabindex="-1" aria-hidden="true">
                            <img src="<?= e(upload_url($navCategory['image_path'], 400)) ?>" alt="" width="180" height="180" loading="lazy">
                        </a>
                        <?php endif ?>
                    </div>
                    <?php endif ?>
                </li>
                <?php endforeach ?>
            </ul>
            <div class="nav-main__highlight">
                <a class="nav-main__link" href="<?= e(url('/produtos?ordem=novidades')) ?>">Novidades</a>
                <a class="nav-main__link nav-main__link--sale" href="<?= e(url('/produtos?oferta=1')) ?>">Ofertas</a>
            </div>
        </div>
    </nav>
    <?php endif ?>
</header>

<main id="conteudo">
    <?= $this->partial('partials/flash', ['flashSuccess' => $flashSuccess ?? null, 'flashError' => $flashError ?? null]) ?>
    <?= $content ?>
</main>

<footer class="site-footer<?= !empty($floatingWhatsapp) ? ' site-footer--float-space' : '' ?>">
    <div class="container footer-grid">
        <div class="footer-brand">
            <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="40" height="40">
            <p class="footer-brand__tagline"><?= e(config('app.tagline')) ?></p>
            <p>Objetos em MDF e madeira cortados a laser e CNC, acabados à mão no nosso ateliê.</p>
        </div>
        <nav class="footer-col" aria-label="Loja">
            <h2>Loja</h2>
            <ul>
                <?php foreach (array_slice($navCategories, 0, 6) as $navCategory): ?>
                <li><a href="<?= e(url('/categoria/' . $navCategory['slug'])) ?>"><?= e($navCategory['name']) ?></a></li>
                <?php endforeach ?>
                <li><a href="<?= e(url('/produtos?oferta=1')) ?>">Ofertas</a></li>
            </ul>
        </nav>
        <nav class="footer-col" aria-label="Ajuda">
            <h2>Ajuda</h2>
            <ul>
                <li><a href="<?= e(url('/como-fazemos')) ?>">Como fazemos</a></li>
                <li><a href="<?= e(url('/trocas-e-devolucoes')) ?>">Trocas e devoluções</a></li>
                <li><a href="<?= e(url('/conta/pedidos')) ?>">Acompanhar pedido</a></li>
                <li><a href="<?= e(url('/privacidade')) ?>">Privacidade</a></li>
                <li><a href="<?= e(url('/termos')) ?>">Termos de uso</a></li>
            </ul>
        </nav>
        <div class="footer-col">
            <h2>Atendimento</h2>
            <ul>
                <li><a href="<?= e(url('/sobre')) ?>">Sobre a G-Nesting</a></li>
                <?php if (!empty($contactEmail)): ?><li><a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a></li><?php endif ?>
                <?php if (!empty($floatingWhatsapp)): ?><li><a href="<?= e($floatingWhatsapp) ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a></li><?php endif ?>
            </ul>
        </div>
    </div>
    <div class="container footer-bottom">
        <span>© <?= e(date('Y')) ?> G-Nesting. Objetos produzidos com fabricação digital.</span>
        <span class="pay-chips" aria-label="Formas de pagamento"><span>Pix</span><span>Cartão</span><span>Boleto</span></span>
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
