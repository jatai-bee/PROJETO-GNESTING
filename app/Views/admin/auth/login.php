<?php /** @var array $errors @var array $old */ ?>
<section class="auth">
    <div class="auth__card">
        <img class="auth__logo" src="<?= e(asset('img/logo-mark.svg')) ?>" alt="G-Nesting" width="48" height="48">
        <h1 class="auth__title">Painel G-Nesting</h1>
        <p class="auth__subtitle">Acesso restrito à equipe.</p>

        <form method="post" action="<?= e(url('/admin/login')) ?>" novalidate>
            <?= csrf_field() ?>
            <?= $this->partial('partials/field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'username', 'errors' => $errors, 'old' => $old]) ?>
            <?= $this->partial('partials/field', ['name' => 'password', 'label' => 'Senha', 'type' => 'password', 'autocomplete' => 'current-password', 'errors' => $errors, 'old' => $old]) ?>
            <button type="submit" class="btn btn--primary btn--block">Entrar</button>
        </form>
    </div>
</section>
