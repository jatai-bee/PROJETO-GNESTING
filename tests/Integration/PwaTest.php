<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Enums\AdminRole;

/**
 * "Salvar no celular": manifestos da loja e do painel, service worker, página sem conexão,
 * metadados nos layouts e a faixa de categorias do celular (docs/19).
 */
final class PwaTest extends HttpTestCase
{
    /** @return array<string, mixed> */
    private function manifest(string $path): array
    {
        $response = $this->get($path);
        self::assertSame(200, $response->status(), $path);
        self::assertStringStartsWith('application/manifest+json', (string) $response->header('Content-Type'));

        return json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testStoreAndPanelManifestsArePublicWithExistingIcons(): void
    {
        $store = $this->manifest('/manifest.webmanifest');
        self::assertSame(['G-Nesting', '/', 'standalone'], [$store['name'], $store['scope'], $store['display']]);
        self::assertStringStartsWith('/?', $store['start_url']);

        // Sem login: o navegador busca o manifesto sem cookies
        $panel = $this->manifest('/admin/manifest.webmanifest');
        self::assertSame(['/admin/', '/admin'], [$panel['scope'], $panel['start_url']]);
        self::assertNotSame($store['icons'][0]['src'], $panel['icons'][0]['src'], 'Ícones diferentes para os dois apps');

        foreach ([...$store['icons'], ...$panel['icons']] as $icon) {
            $file = dirname(__DIR__, 2) . '/public' . (string) strtok($icon['src'], '?');
            self::assertFileExists($file, $icon['src']);
            [$width] = getimagesize($file) ?: [0];
            self::assertSame((int) explode('x', $icon['sizes'])[0], $width, $icon['src']);
        }
        self::assertContains('maskable', array_column($store['icons'], 'purpose'));
    }

    public function testServiceWorkerIsFreshAndNeverStoresPages(): void
    {
        $response = $this->get('/sw.js');
        self::assertSame(200, $response->status());
        self::assertStringStartsWith('application/javascript', (string) $response->header('Content-Type'));
        self::assertSame('/', $response->header('Service-Worker-Allowed'));
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));

        $script = $response->body();
        self::assertMatchesRegularExpression('/const VERSION = "\d+";/', $script);
        self::assertStringContainsString('"/offline"', $script, 'Página offline guardada na instalação');
        self::assertStringContainsString('/assets/css/store.css?v=', $script, 'CSS versionado guardado na instalação');
        self::assertStringContainsString("request.mode === 'navigate'", $script);
        self::assertStringNotContainsString('PAGES_CACHE', $script, 'Páginas (com dados do cliente) nunca vão para o cache');

        $offline = $this->get('/offline');
        self::assertSame(200, $offline->status());
        self::assertStringContainsString('Sem conexão', $offline->body());
        self::assertStringNotContainsString('<style', $offline->body(), 'CSP bloqueia estilo inline');
    }

    public function testLayoutsPointToTheirOwnApp(): void
    {
        $home = $this->get('/')->body();
        self::assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $home);
        self::assertStringContainsString('icons/loja-apple-180.png', $home);
        self::assertStringContainsString('data-sw="/sw.js"', $home);
        self::assertStringContainsString('data-pwa-install hidden', $home, 'Botão só aparece quando o navegador oferece');

        self::assertStringContainsString('href="/admin/manifest.webmanifest"', $this->get('/admin/login')->body(), 'App do painel abre no login');

        $this->loginAdmin(AdminRole::Owner);
        $panel = $this->get('/admin')->body();
        self::assertStringContainsString('href="/admin/manifest.webmanifest"', $panel);
        self::assertStringContainsString('icons/painel-apple-180.png', $panel);
        self::assertStringContainsString('Instalar o painel no celular', $panel);
    }

    public function testMobileCategoryStripMarksTheParentOfASubcategory(): void
    {
        $parent = $this->db->pdo()->query('SELECT id, slug FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY id LIMIT 1')->fetch();
        $this->db->pdo()->exec("INSERT INTO categories (parent_id, name, slug, sort_order, is_active) VALUES ({$parent['id']}, 'Sub da faixa', 'sub-da-faixa', 1, 1)");
        $this->db->pdo()->exec("UPDATE products SET category_id = LAST_INSERT_ID() WHERE id = (SELECT id FROM (SELECT id FROM products WHERE is_active = 1 LIMIT 1) p)");

        $page = $this->get('/categoria/sub-da-faixa')->body();
        self::assertStringContainsString('class="cat-strip"', $page);
        self::assertMatchesRegularExpression('#href="/categoria/' . preg_quote($parent['slug'], '#') . '" aria-current="page"#', $page);
        self::assertMatchesRegularExpression('#cat-strip__chip cat-strip__chip--sale" href="/produtos\?oferta=1" aria-current="page"#', $this->get('/produtos', ['oferta' => '1'])->body());
        self::assertStringContainsString('home-categories', $this->get('/')->body(), 'Seção escondida no celular pelo CSS');
    }
}
