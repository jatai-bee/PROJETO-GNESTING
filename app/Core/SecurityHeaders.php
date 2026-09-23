<?php

declare(strict_types=1);

namespace GNesting\Core;

/**
 * Cabeçalhos de segurança aplicados a toda resposta da aplicação.
 * CSP restritiva: só recursos do próprio domínio, sem scripts/estilos inline.
 */
final class SecurityHeaders
{
    private const CSP = "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
        . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'self'; "
        . "base-uri 'self'; object-src 'none'";

    public function __construct(private readonly Config $config)
    {
    }

    public function apply(Response $response, Request $request): Response
    {
        $headers = [
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            // Páginas dinâmicas contêm token CSRF e dados pessoais: não guardar em cache
            'Cache-Control' => 'no-store, private',
        ];

        if ($this->config->get('app.env') === 'production' && $request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if ($response->header($name) === null) {
                $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
