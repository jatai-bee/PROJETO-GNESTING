<?php
/**
 * Corpo comum das páginas de erro.
 * @var int $status
 * @var string $heading
 * @var string $message
 * @var string|null $errorId
 * @var Throwable|null $exception somente com APP_DEBUG=true fora de produção
 */
?>
<section class="error-box">
    <span class="error-box__code"><?= e($status) ?></span>
    <h1><?= e($heading) ?></h1>
    <p class="lead"><?= e($message) ?></p>
    <?php if (!empty($errorId)): ?>
        <p class="muted">Código do erro: <strong><?= e($errorId) ?></strong></p>
    <?php endif ?>
    <p><a class="btn btn--primary" href="<?= e(url('/')) ?>">Voltar para o início</a></p>

    <?php if (!empty($exception)): ?>
        <details class="debug" open>
            <summary>Detalhes (APP_DEBUG)</summary>
            <p><strong><?= e($exception::class) ?></strong>: <?= e($exception->getMessage()) ?></p>
            <p><?= e($exception->getFile()) ?>:<?= e($exception->getLine()) ?></p>
            <pre><?= e($exception->getTraceAsString()) ?></pre>
        </details>
    <?php endif ?>
</section>
