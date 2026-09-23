<?php

declare(strict_types=1);

namespace GNesting\Middleware;

use Closure;
use GNesting\Core\Auth;
use GNesting\Core\Request;
use GNesting\Core\Response;

/** Telas de entrar/cadastro: cliente já logado vai para a conta. */
final class RedirectIfCustomer implements Middleware
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        if ($this->auth->customer() !== null) {
            return Response::redirect(url('/conta'));
        }

        return $next($request);
    }
}
