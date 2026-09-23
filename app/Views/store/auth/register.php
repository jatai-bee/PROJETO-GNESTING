<?php /** @var array $errors @var array $old */ ?>
<section class="auth">
    <div class="auth__card">
        <h1 class="auth__title">Criar conta</h1>
        <p class="auth__subtitle">Leva menos de um minuto.</p>

        <form method="post" action="<?= e(url('/cadastro')) ?>" novalidate>
            <?= csrf_field() ?>
            <?= $this->partial('partials/field', ['name' => 'name', 'label' => 'Nome completo', 'autocomplete' => 'name', 'errors' => $errors, 'old' => $old]) ?>
            <?= $this->partial('partials/field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'email', 'errors' => $errors, 'old' => $old]) ?>
            <?= $this->partial('partials/field', ['name' => 'password', 'label' => 'Senha', 'type' => 'password', 'autocomplete' => 'new-password', 'hint' => 'Mínimo de ' . (int) config('security.password.min_length', 8) . ' caracteres.', 'errors' => $errors, 'old' => $old]) ?>
            <?= $this->partial('partials/field', ['name' => 'password_confirmation', 'label' => 'Confirme a senha', 'type' => 'password', 'autocomplete' => 'new-password', 'errors' => $errors, 'old' => $old]) ?>
            <button type="submit" class="btn btn--primary btn--block">Criar conta</button>
        </form>

        <p class="auth__alt">Já tem conta? <a href="<?= e(url('/entrar')) ?>">Entrar</a></p>
    </div>
</section>
