<?php

declare(strict_types=1);

namespace GNesting\Middleware;

use Closure;
use GNesting\Core\AuditContext;
use GNesting\Core\Auth;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Session;
use GNesting\Core\View;

/**
 * Painel: exige administrador ativo. Disponibiliza o admin para a requisição,
 * para as views (currentAdmin) e para a auditoria.
 */
final class RequireAdmin implements Middleware
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Session $session,
        private readonly View $view,
        private readonly AuditContext $auditContext,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $admin = $this->auth->admin();
        if ($admin === null) {
            if ($request->isMethod('GET')) {
                $this->session->set('url.intended_admin', $request->path());
            }

            return Response::redirect(url('/admin/login'));
        }

        $request->setAttribute('admin', $admin);
        $this->view->share('currentAdmin', $admin);
        $this->view->share('currentPath', $request->path());
        $this->auditContext->setUserId($admin['user_id']);

        return $next($request);
    }
}
