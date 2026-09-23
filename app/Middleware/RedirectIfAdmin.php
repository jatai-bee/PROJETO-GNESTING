<?php

declare(strict_types=1);

namespace GNesting\Middleware;

use Closure;
use GNesting\Core\Auth;
use GNesting\Core\Request;
use GNesting\Core\Response;

final class RedirectIfAdmin implements Middleware
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        if ($this->auth->admin() !== null) {
            return Response::redirect(url('/admin'));
        }

        return $next($request);
    }
}
