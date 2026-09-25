<?php

declare(strict_types=1);

namespace GNesting\Core;

/**
 * Em produção, todo acesso vai para https:// no host de APP_URL:
 * http://gnesting.com.br/x e https://www.gnesting.com.br/x → https://gnesting.com.br/x (301).
 * Formulários (POST etc.) recebem 308, que preserva o método.
 *
 * Ligado por padrão quando APP_ENV=production e APP_URL é https; APP_FORCE_HTTPS=false desliga.
 */
final class CanonicalUrl
{
    public function __construct(private readonly Config $config)
    {
    }

    public function enabled(): bool
    {
        $forced = $this->config->get('operations.force_https');
        if ($forced !== null && $forced !== '') {
            return (bool) $forced;
        }

        return $this->config->get('app.env') === 'production'
            && str_starts_with((string) $this->config->get('app.url'), 'https://');
    }

    public function redirect(Request $request): ?Response
    {
        if (!$this->enabled()) {
            return null;
        }
        $url = (string) $this->config->get('app.url');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        if ($host === '' || ($request->isSecure() && $request->host() === $host)) {
            return null;
        }

        $target = 'https://' . $host . ($port ? ':' . $port : '') . $request->requestUri();

        return Response::redirect($target, in_array($request->method(), ['GET', 'HEAD'], true) ? 301 : 308);
    }
}
