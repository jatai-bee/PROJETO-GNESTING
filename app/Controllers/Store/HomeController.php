<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\CatalogRepository;
use GNesting\Services\CatalogService;
use GNesting\Services\SeoData;
use GNesting\Services\SettingsService;

final class HomeController extends Controller
{
    private const SHOWCASE = 8;

    public function __construct(
        private readonly CatalogRepository $products,
        private readonly CatalogService $catalog,
        private readonly SettingsService $settings,
    ) {
    }

    public function index(Request $request): Response
    {
        $featured = $this->products->featured(self::SHOWCASE);
        // Novidades sem repetir o que já está em destaque
        $featuredIds = array_column($featured, 'id');
        $newest = array_values(array_filter(
            $this->products->newest(self::SHOWCASE + count($featuredIds)),
            static fn (array $p): bool => !in_array($p['id'], $featuredIds, true)
        ));

        return $this->render('store/home', [
            'title' => 'G-Nesting — Objetos que transformam espaços.',
            'metaDescription' => 'Objetos de design produzidos com fabricação digital: relógios, painéis, organizadores e presentes com personalização.',
            'canonical' => absolute_url('/'),
            'jsonLd' => [SeoData::store($this->settings->get('store.contact_email'))],
            'featured' => $featured,
            'newest' => array_slice($newest, 0, self::SHOWCASE),
            'categories' => array_values(array_filter(
                $this->catalog->categoryTree(),
                static fn (array $c): bool => $c['total'] > 0
            )),
        ]);
    }
}
