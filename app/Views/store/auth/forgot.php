<?php /** @var array $errors @var array $old */ ?>
<section class="auth">
    <div class="auth__card">
        <h1 class="auth__title">Recuperar senha</h1>
        <p class="auth__subtitle">Informe o e-mail da sua conta. Enviaremos um link para criar uma nova senha.</p>

        <form method="post" action="<?= e(url('/recuperar-senha')) ?>" novalidate>
            <?= csrf_field() ?>
            <?= $this->partial('partials/field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'email', 'errors' => $errors, 'old' => $old]) ?>
            <button type="submit" class="btn btn--primary btn--block">Enviar link</button>
        </form>

        <p class="auth__alt">Lembrou? <a href="<?= e(url('/entrar')) ?>">Entrar</a></p>
    </div>
</section>
