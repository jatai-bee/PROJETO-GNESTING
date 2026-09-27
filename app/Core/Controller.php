<?php

declare(strict_types=1);

namespace GNesting\Core;

use GNesting\Repositories\WishlistRepository;
use GNesting\Services\CartService;
use GNesting\Services\CatalogService;
use GNesting\Services\SettingsService;
use GNesting\Services\WhatsApp;

/**
 * Base dos controllers: renderização, redirecionamento e validação.
 * Controllers não contêm SQL nem regra de negócio — delegam para Services.
 */
abstract class Controller
{
    /** @param array<string, mixed> $data */
    protected function render(string $template, array $data = [], string $layout = 'store', int $status = 200): Response
    {
        $session = app(Session::class);

        $data += [
            'errors' => $session->getFlash('errors', []),
            'old' => $session->getFlash('old', []),
            'flashSuccess' => $session->getFlash('success'),
            'flashError' => $session->getFlash('error'),
        ];

        if ($layout === 'store') {
            $customer = app(Auth::class)->customer();
            $data += [
                'currentCustomer' => $customer,
                'favoriteIds' => $customer === null ? [] : app(WishlistRepository::class)->productIds((int) $customer['customer_id']),
                'cartCount' => app(CartService::class)->itemCount(),
                'navCategories' => app(CatalogService::class)->categoryTree(),
                'announcement' => app(SettingsService::class)->get('store.announcement'),
                'contactEmail' => app(SettingsService::class)->get('store.contact_email'),
                'floatingWhatsapp' => app(WhatsApp::class)->floatingButton(),
            ];
            // O coração dos cartões aparece em vários partials: compartilhado, não repassado um a um
            app(View::class)->share('favoriteIds', $data['favoriteIds']);
        }

        return Response::html(app(View::class)->render($template, $data, $layout), $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect(url($path));
    }

    protected function flash(string $key, mixed $value): void
    {
        app(Session::class)->flash($key, $value);
    }

    /**
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     * @param list<string>          $keepOld
     * @throws ValidationException
     */
    protected function validate(Request $request, array $rules, array $labels = [], array $keepOld = []): void
    {
        // Valida os mesmos valores que o controller vai usar: texto limpo via
        // $request->string(); senhas exatamente como digitadas.
        $data = [];
        foreach (array_keys($rules) as $field) {
            $data[$field] = str_contains($field, 'password')
                ? $request->secret($field)
                : $request->string($field);
        }

        Validator::validate($data, $rules, $labels, $keepOld);
    }

    /**
     * Destino seguro após login: só caminhos internos ("/conta"), nunca "//site" ou URL absoluta.
     */
    protected function safeRedirectPath(?string $path, string $fallback): string
    {
        if (!is_string($path) || !preg_match('#^/(?!/)[^\\\\\s]*$#', $path)) {
            return $fallback;
        }

        return $path;
    }
}
