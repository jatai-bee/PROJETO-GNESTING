<?php

declare(strict_types=1);

namespace GNesting\Middleware;

use Closure;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Enums\AdminRole;
use RuntimeException;

/**
 * Autorização por papel. Uso na rota: 'role:manager,production'.
 * Deve vir depois de 'admin'. O proprietário (owner) sempre passa.
 */
final class RequireAdminRole implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $admin = $request->attribute('admin');
        if (!is_array($admin)) {
            throw new RuntimeException("O middleware 'role' exige o middleware 'admin' antes dele.");
        }

        $role = AdminRole::tryFrom((string) $admin['role']);
        if ($role === null || !$role->isAllowed($params)) {
            throw HttpException::forbidden();
        }

        return $next($request);
    }
}
