<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Controllers\Store\PasswordResetController as StorePasswordReset;
use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\ValidationException;
use GNesting\Enums\UserType;
use GNesting\Services\AdminUserService;
use GNesting\Services\Auth\InvalidResetTokenException;
use GNesting\Services\Auth\PasswordResetService;
use GNesting\Services\Auth\TooManyAttemptsException;

/** "Esqueci minha senha" do painel: só contas de administrador ativas recebem o link. */
final class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $resets)
    {
    }

    public function showRequest(Request $request): Response
    {
        return $this->render('admin/auth/forgot', ['title' => 'Recuperar senha | Painel G-Nesting'], 'admin_auth');
    }

    public function request(Request $request): Response
    {
        $this->validate($request, ['email' => 'required|email|max:190'], ['email' => 'E-mail'], ['email']);
        try {
            $this->resets->request($request->string('email'), UserType::Admin, $request->ip());
        } catch (TooManyAttemptsException $e) {
            throw new ValidationException(['email' => $e->getMessage()], ['email' => $request->string('email')]);
        }
        $this->flash('success', StorePasswordReset::SENT_MESSAGE);

        return $this->redirect('/admin/login');
    }

    public function showReset(Request $request): Response
    {
        $token = (string) $request->param('token');
        if (!$this->resets->isValid($token, UserType::Admin)) {
            $this->flash('error', (new InvalidResetTokenException())->getMessage());

            return $this->redirect('/admin/recuperar-senha');
        }

        return StorePasswordReset::noReferrer($this->render('admin/auth/reset', [
            'title' => 'Nova senha | Painel G-Nesting', 'token' => $token,
        ], 'admin_auth'));
    }

    public function reset(Request $request): Response
    {
        $token = (string) $request->param('token');
        $this->validate($request, [
            'password' => 'required|min:' . AdminUserService::MIN_PASSWORD . '|max:128',
            'password_confirmation' => 'required|same:password',
        ], ['password' => 'Nova senha', 'password_confirmation' => 'Confirmação de senha']);

        try {
            $this->resets->reset($token, UserType::Admin, $request->secret('password'));
        } catch (InvalidResetTokenException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/admin/recuperar-senha');
        }
        $this->flash('success', 'Senha alterada. Entre com a nova senha.');

        return $this->redirect('/admin/login');
    }
}
