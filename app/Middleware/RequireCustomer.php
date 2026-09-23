<?php

declare(strict_types=1);

namespace GNesting\Middleware;

use Closure;
use GNesting\Core\Auth;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Session;

/** Área do cliente: exige login e guarda o destino para voltar após entrar. */
final class RequireCustomer implements Middleware
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Session $session,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $customer = $this->auth->customer();
        if ($customer === null) {
            if ($request->isMethod('GET')) {
                $this->session->set('url.intended', $request->path());
            }

            return Response::redirect(url('/entrar'));
        }

        $request->setAttribute('customer', $customer);

        return $next($request);
    }
}
