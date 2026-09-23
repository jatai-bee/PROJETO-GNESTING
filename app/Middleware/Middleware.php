<?php

declare(strict_types=1);

namespace GNesting\Middleware;

use Closure;
use GNesting\Core\Request;
use GNesting\Core\Response;

interface Middleware
{
    /**
     * @param Closure(Request): Response $next
     * @param string ...$params parâmetros da rota, ex.: 'role:manager,production' → 'manager', 'production'
     */
    public function handle(Request $request, Closure $next, string ...$params): Response;
}
