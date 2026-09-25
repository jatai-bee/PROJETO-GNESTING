<?php /** @var array $errors @var array $old */ ?>
<section class="auth">
    <div class="auth__card">
        <img class="auth__logo" src="<?= e(asset('img/logo-mark.svg')) ?>" alt="G-Nesting" width="48" height="48">
        <h1 class="auth__title">Recuperar senha</h1>
        <p class="auth__subtitle">Enviaremos um link para o e-mail cadastrado no painel.</p>

        <form method="post" action="<?= e(url('/admin/recuperar-senha')) ?>" novalidate>
            <?= csrf_field() ?>
            <?= $this->partial('partials/field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'username', 'errors' => $errors, 'old' => $old]) ?>
            <button type="submit" class="btn btn--primary btn--block">Enviar link</button>
        </form>

        <p class="auth__alt"><a href="<?= e(url('/admin/login')) ?>">Voltar para o login</a></p>
    </div>
</section>
