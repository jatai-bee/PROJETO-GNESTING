<?php /** @var array $errors @var array $old @var string $token */ ?>
<section class="auth">
    <div class="auth__card">
        <img class="auth__logo" src="<?= e(asset('img/logo-mark.svg')) ?>" alt="G-Nesting" width="48" height="48">
        <h1 class="auth__title">Nova senha</h1>
        <p class="auth__subtitle">Depois de salvar, as sessões abertas em outros aparelhos serão encerradas.</p>

        <form method="post" action="<?= e(url('/admin/redefinir-senha/' . $token)) ?>" novalidate>
            <?= csrf_field() ?>
            <?= $this->partial('partials/field', ['name' => 'password', 'label' => 'Nova senha', 'type' => 'password', 'autocomplete' => 'new-password', 'hint' => 'Mínimo de ' . \GNesting\Services\AdminUserService::MIN_PASSWORD . ' caracteres.', 'errors' => $errors, 'old' => $old]) ?>
            <?= $this->partial('partials/field', ['name' => 'password_confirmation', 'label' => 'Confirme a nova senha', 'type' => 'password', 'autocomplete' => 'new-password', 'errors' => $errors, 'old' => $old]) ?>
            <button type="submit" class="btn btn--primary btn--block">Salvar nova senha</button>
        </form>
    </div>
</section>
