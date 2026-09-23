<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Auth;
use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Session;
use GNesting\Core\ValidationException;
use GNesting\Services\AuditService;
use GNesting\Services\Auth\AuthenticationException;
use GNesting\Services\Auth\AuthService;
use GNesting\Services\Auth\TooManyAttemptsException;

final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly Auth $auth,
        private readonly Session $session,
        private readonly AuditService $audit,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        return $this->render('admin/auth/login', ['title' => 'Entrar no painel | G-Nesting'], 'admin_auth');
    }

    public function login(Request $request): Response
    {
        $this->validate($request, [
            'email' => 'required|email|max:190',
            'password' => 'required|max:128',
        ], ['email' => 'E-mail', 'password' => 'Senha'], ['email']);

        try {
            $admin = $this->authService->attemptAdmin(
                $request->string('email'),
                $request->secret('password'),
                $request->ip(),
            );
        } catch (AuthenticationException | TooManyAttemptsException $e) {
            throw new ValidationException(['email' => $e->getMessage()], ['email' => $request->string('email')]);
        }

        $this->auth->loginAdmin($admin);

        $target = $this->safeRedirectPath($this->session->pull('url.intended_admin'), '/admin');

        return $this->redirect(str_starts_with($target, '/admin') ? $target : '/admin');
    }

    public function logout(Request $request): Response
    {
        $admin = $request->attribute('admin');
        $this->audit->record(AuditService::LOGOUT, 'admin', (int) $admin['admin_id']);
        $this->auth->logoutAdmin();
        $this->flash('success', 'Você saiu do painel.');

        return $this->redirect('/admin/login');
    }
}
