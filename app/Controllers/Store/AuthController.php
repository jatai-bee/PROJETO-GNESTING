<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Auth;
use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Session;
use GNesting\Core\ValidationException;
use GNesting\Services\Auth\AuthenticationException;
use GNesting\Services\Auth\AuthService;
use GNesting\Services\Auth\TooManyAttemptsException;
use GNesting\Services\CartService;

/** Entrar, criar conta e sair (clientes). */
final class AuthController extends Controller
{
    private const LABELS = [
        'name' => 'Nome',
        'email' => 'E-mail',
        'password' => 'Senha',
        'password_confirmation' => 'Confirmação de senha',
    ];

    public function __construct(
        private readonly AuthService $authService,
        private readonly Auth $auth,
        private readonly Session $session,
        private readonly CartService $cart,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        // ?voltar=/checkout: depois de entrar, volta para onde estava (só caminhos internos)
        $back = $this->safeRedirectPath($request->queryString('voltar', 200), '');
        if ($back !== '') {
            $this->session->set('url.intended', $back);
        }

        return $this->render('store/auth/login', ['title' => 'Entrar | G-Nesting']);
    }

    public function login(Request $request): Response
    {
        $this->validate($request, [
            'email' => 'required|email|max:190',
            'password' => 'required|max:128',
        ], self::LABELS, ['email']);

        try {
            $customer = $this->authService->attemptCustomer(
                $request->string('email'),
                $request->secret('password'),
                $request->ip(),
            );
        } catch (AuthenticationException | TooManyAttemptsException $e) {
            throw new ValidationException(['email' => $e->getMessage()], ['email' => $request->string('email')]);
        }

        $this->auth->loginCustomer($customer);
        $this->cart->adoptForCustomer((int) $customer['customer_id']);

        return $this->redirect($this->safeRedirectPath($this->session->pull('url.intended'), '/conta'));
    }

    public function showRegister(Request $request): Response
    {
        return $this->render('store/auth/register', ['title' => 'Criar conta | G-Nesting']);
    }

    public function register(Request $request): Response
    {
        $min = (int) config('security.password.min_length', 8);
        $max = (int) config('security.password.max_length', 128);

        $this->validate($request, [
            'name' => 'required|min:2|max:120',
            'email' => 'required|email|max:190',
            'password' => "required|min:{$min}|max:{$max}",
            'password_confirmation' => 'required|same:password',
        ], self::LABELS, ['name', 'email']);

        try {
            $customer = $this->authService->registerCustomer(
                $request->string('name'),
                $request->string('email'),
                $request->secret('password'),
                $request->ip(),
            );
        } catch (TooManyAttemptsException $e) {
            throw new ValidationException(['email' => $e->getMessage()], [
                'name' => $request->string('name'),
                'email' => $request->string('email'),
            ]);
        }

        $this->auth->loginCustomer($customer);
        $this->cart->adoptForCustomer((int) $customer['customer_id']);
        $this->flash('success', 'Conta criada. Boas-vindas à G-Nesting!');

        return $this->redirect($this->safeRedirectPath($this->session->pull('url.intended'), '/conta'));
    }

    public function logout(Request $request): Response
    {
        $this->auth->logoutCustomer();
        $this->flash('success', 'Você saiu da sua conta.');

        return $this->redirect('/');
    }
}
