<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\CatalogRepository;

/**
 * /sitemap.xml e /robots.txt, gerados na hora (catálogo pequeno; sempre atualizados).
 * Fora de produção, robots.txt bloqueia tudo: uma cópia de homologação nunca é indexada.
 */
final class SeoController extends Controller
{
    private const PAGES = ['/sobre', '/como-fazemos', '/trocas-e-devolucoes', '/privacidade', '/termos'];

    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    public function sitemap(Request $request): Response
    {
        $urls = [['loc' => absolute_url('/'), 'priority' => '1.0', 'changefreq' => 'daily']];
        $urls[] = ['loc' => absolute_url('/produtos'), 'priority' => '0.9', 'changefreq' => 'daily'];
        foreach ($this->catalog->visibleCategories() as $category) {
            if ((int) $category['product_count'] > 0 || $category['parent_id'] === null) {
                $urls[] = ['loc' => absolute_url('/categoria/' . $category['slug']), 'priority' => '0.8', 'changefreq' => 'weekly'];
            }
        }
        foreach ($this->catalog->sitemapProducts() as $product) {
            $urls[] = [
                'loc' => absolute_url('/produto/' . $product['slug']),
                'lastmod' => substr((string) $product['updated_at'], 0, 10),
                'priority' => '0.7',
                'changefreq' => 'weekly',
                'image' => $product['cover_path'] ? absolute_upload_url((string) $product['cover_path']) : null,
            ];
        }
        foreach (self::PAGES as $page) {
            $urls[] = ['loc' => absolute_url($page), 'priority' => '0.3', 'changefreq' => 'yearly'];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . $this->xml($url['loc']) . '</loc>'
                . (isset($url['lastmod']) ? '<lastmod>' . $this->xml($url['lastmod']) . '</lastmod>' : '')
                . '<changefreq>' . $url['changefreq'] . '</changefreq><priority>' . $url['priority'] . '</priority>'
                . (!empty($url['image']) ? '<image:image><image:loc>' . $this->xml($url['image']) . '</image:loc></image:image>' : '')
                . "</url>\n";
        }
        $xml .= '</urlset>' . "\n";

        return (new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']))
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function robots(Request $request): Response
    {
        if (config('app.env') !== 'production') {
            $body = "# Ambiente de " . config('app.env') . ": nada deve ser indexado\nUser-agent: *\nDisallow: /\n";
        } else {
            $body = "User-agent: *\n"
                . "Disallow: /admin\nDisallow: /carrinho\nDisallow: /checkout\nDisallow: /conta\n"
                . "Disallow: /pedido/\nDisallow: /busca\nDisallow: /entrar\nDisallow: /cadastro\nDisallow: /pagamento-simulado/\n"
                . "Disallow: /saude\nDisallow: /recuperar-senha\nDisallow: /redefinir-senha/\n"
                . "\nSitemap: " . absolute_url('/sitemap.xml') . "\n";
        }

        return (new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']))
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
