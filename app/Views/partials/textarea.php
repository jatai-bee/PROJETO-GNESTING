<?php
/**
 * @var string      $name
 * @var string      $label
 * @var mixed       $value
 * @var int|null    $rows
 * @var int|null    $maxlength
 * @var string|null $hint
 * @var array       $errors
 * @var array       $old
 * @var bool|null   $required
 */
$errors ??= [];
$old ??= [];
$error = $errors[$name] ?? null;
$current = array_key_exists($name, $old) ? $old[$name] : ($value ?? '');
$hint ??= null;
$describedBy = trim(($hint ? "{$name}-hint " : '') . ($error ? "{$name}-error" : ''));
?>
<div class="field<?= $error ? ' field--invalid' : '' ?>">
    <label for="<?= e($name) ?>"><?= e($label) ?><?php if (!($required ?? false)): ?> <span class="field__optional">(opcional)</span><?php endif ?></label>
    <textarea
        id="<?= e($name) ?>"
        name="<?= e($name) ?>"
        rows="<?= e($rows ?? 4) ?>"
        <?php if (!empty($maxlength)): ?>maxlength="<?= e($maxlength) ?>"<?php endif ?>
        <?php if ($required ?? false): ?>required<?php endif ?>
        <?php if ($error): ?>aria-invalid="true"<?php endif ?>
        <?php if ($describedBy !== ''): ?>aria-describedby="<?= e($describedBy) ?>"<?php endif ?>
    ><?= e($current) ?></textarea>
    <?php if ($hint): ?><small id="<?= e($name) ?>-hint" class="field__hint"><?= e($hint) ?></small><?php endif ?>
    <?php if ($error): ?><p id="<?= e($name) ?>-error" class="field__error"><?= e($error) ?></p><?php endif ?>
</div>
