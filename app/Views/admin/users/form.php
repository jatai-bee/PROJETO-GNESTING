<?php
/**
 * @var array<string, mixed>|null $user
 * @var array{admin_id:int} $currentAdmin
 * @var array $errors
 * @var array $old
 */
use GNesting\Enums\AdminRole;

$f = fn (string $partial, array $vars): string => $this->partial($partial, $vars + ['errors' => $errors, 'old' => $old]);
$roles = [];
foreach (AdminRole::cases() as $role) {
    $roles[$role->value] = $role->label();
}
$isSelf = $user !== null && (int) $user['admin_id'] === (int) $currentAdmin['admin_id'];
$action = $user ? "/admin/usuarios/{$user['admin_id']}/editar" : '/admin/usuarios/novo';
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url('/admin/usuarios')) ?>">← Usuários</a>
        <h1 class="page-title"><?= $user ? e($user['name']) : 'Novo usuário' ?></h1>
    </div>
</div>

<form method="post" action="<?= e(url($action)) ?>" class="form-layout" novalidate autocomplete="off">
    <?= csrf_field() ?>
    <section class="panel">
        <?= $f('partials/field', ['name' => 'name', 'label' => 'Nome', 'value' => $user['name'] ?? '', 'maxlength' => 120]) ?>
        <?php if ($user): ?>
            <div class="field"><span class="field__label">E-mail</span><p><?= e($user['email']) ?></p></div>
        <?php else: ?>
            <?= $f('partials/field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'off']) ?>
        <?php endif ?>

        <?php if ($isSelf): ?>
            <div class="field"><span class="field__label">Papel</span><p><?= e(AdminRole::from($user['role'])->label()) ?> <small class="muted">(você não pode alterar o próprio papel)</small></p></div>
            <input type="hidden" name="role" value="<?= e($user['role']) ?>">
            <input type="hidden" name="is_active" value="1">
        <?php else: ?>
            <?= $f('partials/select', ['name' => 'role', 'label' => 'Papel', 'options' => $roles, 'value' => $user['role'] ?? 'manager']) ?>
            <?php if ($user): ?>
                <?= $f('partials/checkbox', ['name' => 'is_active', 'label' => 'Acesso ativo', 'checked' => (bool) $user['is_active'],
                    'hint' => 'Desmarque para bloquear o acesso sem apagar o histórico.']) ?>
            <?php endif ?>
        <?php endif ?>

        <?php if (!$user): ?>
            <?= $f('partials/field', ['name' => 'password', 'label' => 'Senha inicial', 'type' => 'password', 'autocomplete' => 'new-password',
                'hint' => 'Mínimo de 12 caracteres. Combine com a pessoa um canal seguro para enviá-la.']) ?>
            <?= $f('partials/field', ['name' => 'password_confirmation', 'label' => 'Confirme a senha', 'type' => 'password', 'autocomplete' => 'new-password']) ?>
        <?php endif ?>
    </section>
    <div class="form-actions">
        <button type="submit" class="btn btn--primary"><?= $user ? 'Salvar alterações' : 'Criar usuário' ?></button>
        <a class="btn btn--secondary" href="<?= e(url('/admin/usuarios')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($user): ?>
    <form method="post" action="<?= e(url("/admin/usuarios/{$user['admin_id']}/senha")) ?>" class="panel form-layout" autocomplete="off">
        <?= csrf_field() ?>
        <h2 class="panel__title">Redefinir senha</h2>
        <div class="form-grid">
            <?= $this->partial('partials/field', ['name' => 'password', 'label' => 'Nova senha', 'type' => 'password', 'autocomplete' => 'new-password', 'hint' => 'Mínimo de 12 caracteres.']) ?>
            <?= $this->partial('partials/field', ['name' => 'password_confirmation', 'label' => 'Confirme a nova senha', 'type' => 'password', 'autocomplete' => 'new-password']) ?>
        </div>
        <div><button type="submit" class="btn btn--secondary">Redefinir senha</button></div>
    </form>
<?php endif ?>
