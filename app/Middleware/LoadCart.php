<?php

declare(strict_types=1);

namespace GNesting\Middleware;

use Closure;
use GNesting\Core\CartContext;
use GNesting\Core\Config;
use GNesting\Core\Request;
use GNesting\Core\Response;

/**
 * Lê o token do carrinho do cookie e, se um carrinho novo foi criado na requisição,
 * grava o cookie na resposta (HttpOnly, SameSite=Lax, Secure em produção).
 */
final class LoadCart implements Middleware
{
    public function __construct(
        private readonly CartContext $context,
        private readonly Config $config,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $name = (string) $this->config->get('cart.cookie', 'gn_cart');
        $this->context->reset($request->cookie($name));

        $response = $next($request);

        if ($this->context->wasIssued() && $this->context->token() !== null) {
            $response->withCookie($name, $this->context->token(), [
                'expires' => time() + (int) $this->config->get('cart.lifetime_days', 30) * 86400,
                'path' => ((string) $this->config->get('app.base_path', '')) . '/',
                'secure' => (bool) $this->config->get('security.session.secure_cookie', false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $response;
    }
}
