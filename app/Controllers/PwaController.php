<?php

declare(strict_types=1);

namespace GNesting\Controllers;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\View;

/**
 * "Salvar no celular" (aplicativo web): manifesto da loja, manifesto do painel, service worker e página offline.
 * Tudo gerado pela aplicação para respeitar a subpasta da instalação (url()) e a versão dos arquivos (asset()).
 */
final class PwaController extends Controller
{
    public const THEME_COLOR = '#1F1E1C';
    public const BACKGROUND_COLOR = '#F6F3EE';

    public function __construct(private readonly View $view)
    {
    }

    public function storeManifest(Request $request): Response
    {
        return $this->manifest([
            'id' => url('/'),
            'name' => 'G-Nesting',
            'short_name' => 'G-Nesting',
            'description' => (string) config('app.tagline', 'Objetos que transformam espaços.'),
            'start_url' => url('/') . '?origem=app',
            'scope' => url('/'),
            'background_color' => self::BACKGROUND_COLOR,
            'theme_color' => self::THEME_COLOR,
            'categories' => ['shopping', 'lifestyle'],
            'icons' => $this->icons('loja'),
            'shortcuts' => [
                ['name' => 'Meus pedidos', 'url' => url('/conta/pedidos'), 'icons' => [$this->icon('loja', 192)]],
                ['name' => 'Carrinho', 'url' => url('/carrinho'), 'icons' => [$this->icon('loja', 192)]],
                ['name' => 'Favoritos', 'url' => url('/conta/favoritos'), 'icons' => [$this->icon('loja', 192)]],
            ],
        ]);
    }

    public function adminManifest(Request $request): Response
    {
        return $this->manifest([
            'id' => url('/admin/'),
            'name' => 'G-Nesting Painel',
            'short_name' => 'Painel',
            'description' => 'Pedidos, produção, estoque e expedição da G-Nesting.',
            'start_url' => url('/admin'),
            'scope' => url('/admin/'),
            'background_color' => self::THEME_COLOR,
            'theme_color' => self::THEME_COLOR,
            'categories' => ['business', 'productivity'],
            'icons' => $this->icons('painel'),
            'shortcuts' => [
                ['name' => 'Pedidos', 'url' => url('/admin/pedidos'), 'icons' => [$this->icon('painel', 192)]],
                ['name' => 'Fila de produção', 'url' => url('/admin/producao'), 'icons' => [$this->icon('painel', 192)]],
                ['name' => 'Expedição', 'url' => url('/admin/expedicao'), 'icons' => [$this->icon('painel', 192)]],
            ],
        ]);
    }

    /**
     * Service worker único para os dois aplicativos. Nunca guardado em cache (ele controla o cache);
     * o escopo é a raiz da loja, por isso o cabeçalho Service-Worker-Allowed.
     */
    public function serviceWorker(Request $request): Response
    {
        $script = $this->view->render('pwa/service-worker', [
            'version' => self::version(),
            'base' => rtrim(url('/'), '/'),
            'precache' => [
                url('/offline'),
                asset('css/tokens.css'),
                asset('css/app.css'),
                asset('css/store.css'),
                asset('js/store.js'),
                asset('img/logo.svg'),
                asset('img/logo-mark.svg'),
            ],
        ]);

        return new Response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Service-Worker-Allowed' => url('/'),
        ]);
    }

    /** Mostrada pelo service worker quando não há conexão. Só usa arquivos que ele já guardou. */
    public function offline(Request $request): Response
    {
        return (new Response($this->view->render('pwa/offline', ['title' => 'Sem conexão | G-Nesting']), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]));
    }

    /** Versão do cache = data mais recente dos arquivos de estilo, script e ícones (a mesma lógica de asset()). */
    public static function version(): string
    {
        $base = rtrim((string) config('paths.base', ''), '/') . '/public/assets';
        $latest = 0;
        foreach (['css/*.css', 'js/*.js', 'icons/*.png'] as $pattern) {
            foreach (glob($base . '/' . $pattern) ?: [] as $file) {
                $latest = max($latest, (int) filemtime($file));
            }
        }

        return (string) $latest;
    }

    /** @param array<string, mixed> $manifest */
    private function manifest(array $manifest): Response
    {
        $manifest += ['lang' => 'pt-BR', 'dir' => 'ltr', 'display' => 'standalone', 'orientation' => 'portrait'];
        $body = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return new Response($body, 200, [
            'Content-Type' => 'application/manifest+json; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /** @return list<array<string, string>> */
    private function icons(string $app): array
    {
        return [
            $this->icon($app, 192),
            $this->icon($app, 512),
            ['src' => asset("icons/{$app}-maskable-512.png"), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }

    /** @return array<string, string> */
    private function icon(string $app, int $size): array
    {
        return ['src' => asset("icons/{$app}-{$size}.png"), 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
    }
}
