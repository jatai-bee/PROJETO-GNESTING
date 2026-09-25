<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\ValidationException;
use GNesting\Enums\UserType;
use GNesting\Services\Auth\InvalidResetTokenException;
use GNesting\Services\Auth\PasswordResetService;
use GNesting\Services\Auth\TooManyAttemptsException;

/** "Esqueci minha senha" da loja. O painel tem o seu (Admin\PasswordResetController). */
final class PasswordResetController extends Controller
{
    public const SENT_MESSAGE = 'Se houver uma conta com este e-mail, enviamos um link para criar uma nova senha. Confira também o spam.';

    public function __construct(private readonly PasswordResetService $resets)
    {
    }

    public function showRequest(Request $request): Response
    {
        return $this->render('store/auth/forgot', ['title' => 'Recuperar senha | G-Nesting', 'action' => '/recuperar-senha', 'login' => '/entrar']);
    }

    public function request(Request $request): Response
    {
        $this->validate($request, ['email' => 'required|email|max:190'], ['email' => 'E-mail'], ['email']);
        try {
            $this->resets->request($request->string('email'), UserType::Customer, $request->ip());
        } catch (TooManyAttemptsException $e) {
            throw new ValidationException(['email' => $e->getMessage()], ['email' => $request->string('email')]);
        }
        $this->flash('success', self::SENT_MESSAGE);

        return $this->redirect('/entrar');
    }

    public function showReset(Request $request): Response
    {
        $token = (string) $request->param('token');
        if (!$this->resets->isValid($token, UserType::Customer)) {
            $this->flash('error', (new InvalidResetTokenException())->getMessage());

            return $this->redirect('/recuperar-senha');
        }

        return self::noReferrer($this->render('store/auth/reset', [
            'title' => 'Nova senha | G-Nesting', 'action' => '/redefinir-senha/' . $token,
        ]));
    }

    public function reset(Request $request): Response
    {
        $token = (string) $request->param('token');
        $min = (int) config('security.password.min_length', 8);
        $max = (int) config('security.password.max_length', 128);
        $this->validate($request, [
            'password' => "required|min:{$min}|max:{$max}",
            'password_confirmation' => 'required|same:password',
        ], ['password' => 'Nova senha', 'password_confirmation' => 'Confirmação de senha']);

        try {
            $this->resets->reset($token, UserType::Customer, $request->secret('password'));
        } catch (InvalidResetTokenException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/recuperar-senha');
        }
        $this->flash('success', 'Senha alterada. Entre com a nova senha.');

        return $this->redirect('/entrar');
    }

    /** O token está na URL: a página não deve repassá-lo a nenhum outro site. */
    public static function noReferrer(Response $response): Response
    {
        return $response->withHeader('Referrer-Policy', 'no-referrer');
    }
}
