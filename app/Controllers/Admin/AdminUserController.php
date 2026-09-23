<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Validator;
use GNesting\Enums\AdminRole;
use GNesting\Repositories\AdminRepository;
use GNesting\Services\AdminUserService;
use GNesting\Services\BusinessRuleException;

/** Equipe do painel (somente proprietário). */
final class AdminUserController extends Controller
{
    private const LABELS = [
        'name' => 'Nome', 'email' => 'E-mail', 'role' => 'Papel',
        'password' => 'Senha', 'password_confirmation' => 'Confirmação de senha',
    ];

    public function __construct(
        private readonly AdminRepository $admins,
        private readonly AdminUserService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render('admin/users/index', [
            'title' => 'Usuários do painel | Painel',
            'admins' => $this->admins->listAll(),
        ], 'admin');
    }

    public function create(Request $request): Response
    {
        return $this->render('admin/users/form', ['title' => 'Novo usuário | Painel', 'user' => null], 'admin');
    }

    public function store(Request $request): Response
    {
        $min = AdminUserService::MIN_PASSWORD;
        $this->validate($request, [
            'name' => 'required|max:120',
            'email' => 'required|email|max:190',
            'role' => 'required|in:' . implode(',', array_column(AdminRole::cases(), 'value')),
            'password' => "required|min:{$min}|max:128",
            'password_confirmation' => 'required|same:password',
        ], self::LABELS);

        $this->service->create(
            $request->string('name'),
            $request->string('email'),
            AdminRole::from($request->string('role')),
            $request->secret('password'),
        );
        $this->flash('success', 'Usuário criado. Passe a senha para a pessoa por um canal seguro.');

        return $this->redirect('/admin/usuarios');
    }

    public function edit(Request $request): Response
    {
        return $this->render('admin/users/form', [
            'title' => 'Editar usuário | Painel',
            'user' => $this->findOrFail($request),
        ], 'admin');
    }

    public function update(Request $request): Response
    {
        $user = $this->findOrFail($request);
        $this->validate($request, [
            'name' => 'required|max:120',
            'role' => 'required|in:' . implode(',', array_column(AdminRole::cases(), 'value')),
        ], self::LABELS);

        try {
            $this->service->update(
                (int) $user['admin_id'],
                $request->string('name'),
                AdminRole::from($request->string('role')),
                $request->boolean('is_active'),
                (int) $request->attribute('admin')['admin_id'],
            );
            $this->flash('success', 'Usuário atualizado.');
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect("/admin/usuarios/{$user['admin_id']}/editar");
        }

        return $this->redirect('/admin/usuarios');
    }

    public function password(Request $request): Response
    {
        $user = $this->findOrFail($request);
        $back = "/admin/usuarios/{$user['admin_id']}/editar";

        $errors = Validator::errors([
            'password' => $request->secret('password'),
            'password_confirmation' => $request->secret('password_confirmation'),
        ], [
            'password' => 'required|min:' . AdminUserService::MIN_PASSWORD . '|max:128',
            'password_confirmation' => 'required|same:password',
        ], self::LABELS);

        if ($errors !== []) {
            $this->flash('error', implode(' ', $errors));

            return $this->redirect($back);
        }

        $this->service->resetPassword((int) $user['admin_id'], $request->secret('password'));
        $this->flash('success', 'Senha redefinida.');

        return $this->redirect($back);
    }

    /** @return array<string, mixed> */
    private function findOrFail(Request $request): array
    {
        return $this->admins->findById((int) $request->param('id')) ?? throw HttpException::notFound();
    }
}
