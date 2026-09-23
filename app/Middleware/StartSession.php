<?php

declare(strict_types=1);

namespace GNesting\Middleware;

use Closure;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Session;

final class StartSession implements Middleware
{
    public function __construct(private readonly Session $session)
    {
    }

    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $this->session->start();

        return $next($request);
    }
}
