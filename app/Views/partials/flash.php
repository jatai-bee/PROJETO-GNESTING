<?php
/**
 * @var string|null $flashSuccess
 * @var string|null $flashError
 */
?>
<?php if (!empty($flashSuccess) || !empty($flashError)): ?>
<div class="container flash-area">
    <?php if (!empty($flashSuccess)): ?>
        <p class="alert alert--success" role="status"><?= e($flashSuccess) ?></p>
    <?php endif ?>
    <?php if (!empty($flashError)): ?>
        <p class="alert alert--error" role="alert"><?= e($flashError) ?></p>
    <?php endif ?>
</div>
<?php endif ?>
