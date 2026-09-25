<?php /** @var array $errors @var array $old @var string $action */ ?>
<section class="auth">
    <div class="auth__card">
        <h1 class="auth__title">Nova senha</h1>
        <p class="auth__subtitle">Depois de salvar, as sessões abertas em outros aparelhos serão encerradas.</p>

        <form method="post" action="<?= e(url($action)) ?>" novalidate>
            <?= csrf_field() ?>
            <?= $this->partial('partials/field', ['name' => 'password', 'label' => 'Nova senha', 'type' => 'password', 'autocomplete' => 'new-password', 'hint' => 'Mínimo de ' . (int) config('security.password.min_length', 8) . ' caracteres.', 'errors' => $errors, 'old' => $old]) ?>
            <?= $this->partial('partials/field', ['name' => 'password_confirmation', 'label' => 'Confirme a nova senha', 'type' => 'password', 'autocomplete' => 'new-password', 'errors' => $errors, 'old' => $old]) ?>
            <button type="submit" class="btn btn--primary btn--block">Salvar nova senha</button>
        </form>
    </div>
</section>
