<?php

declare(strict_types=1);

namespace GNesting\Middleware;

use Closure;
use GNesting\Core\Config;
use GNesting\Core\Csrf;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Session;

/**
 * Exige token CSRF em todo método que altera estado.
 * Aceita o campo "_token" do formulário ou o cabeçalho X-CSRF-Token (fetch).
 */
final class VerifyCsrfToken implements Middleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly Session $session,
    ) {
    }

    private function postMaxBytes(): int
    {
        $value = trim((string) ini_get('post_max_size'));
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        } ?: PHP_INT_MAX;
    }

    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true) || $this->isExcept($request->path())) {
            return $next($request);
        }

        // Envio maior que post_max_size: o PHP descarta todo o corpo (inclusive o token).
        // Sem isso, o usuário veria "sessão expirada" em vez do motivo real.
        if ($request->all() === [] && $request->contentLength() > $this->postMaxBytes()) {
            $this->session->flash('error', 'O envio excedeu o limite do servidor ('
                . ini_get('post_max_size') . '). Envie menos arquivos por vez ou arquivos menores.');

            return Response::redirect(url($request->path()));
        }

        $token = $request->input('_token');
        if (!is_string($token)) {
            $token = $request->header('X-CSRF-Token');
        }

        if (!$this->csrf->validate($token)) {
            throw HttpException::csrf();
        }

        return $next($request);
    }

    private function isExcept(string $path): bool
    {
        foreach ($this->config->get('security.csrf_except', []) as $prefix) {
            if (str_starts_with($path . '/', rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }
}
