<?php /** @var array $errors @var array $old */ ?>
<section class="auth">
    <div class="auth__card">
        <h1 class="auth__title">Entrar</h1>
        <p class="auth__subtitle">Acompanhe seus pedidos e compre mais rápido.</p>

        <form method="post" action="<?= e(url('/entrar')) ?>" novalidate>
            <?= csrf_field() ?>
            <?= $this->partial('partials/field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'email', 'errors' => $errors, 'old' => $old]) ?>
            <?= $this->partial('partials/field', ['name' => 'password', 'label' => 'Senha', 'type' => 'password', 'autocomplete' => 'current-password', 'errors' => $errors, 'old' => $old]) ?>
            <button type="submit" class="btn btn--primary btn--block">Entrar</button>
        </form>

        <p class="auth__alt"><a href="<?= e(url('/recuperar-senha')) ?>">Esqueci minha senha</a></p>
        <p class="auth__alt">Ainda não tem conta? <a href="<?= e(url('/cadastro')) ?>">Criar conta</a></p>
    </div>
</section>
