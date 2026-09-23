<?php
/**
 * Campo de formulário com rótulo, valor e mensagem de erro acessível.
 * Depois de um erro de validação, o valor digitado ($old) tem prioridade sobre $value.
 *
 * @var string      $name
 * @var string      $label
 * @var string|null $type
 * @var mixed       $value         valor atual (edição)
 * @var string|null $autocomplete
 * @var string|null $hint
 * @var string|null $placeholder
 * @var string|null $inputmode     ex.: numeric, decimal
 * @var int|null    $maxlength
 * @var string|null $suffix        unidade exibida à direita (mm, g, dias)
 * @var string|null $prefix        símbolo exibido à esquerda (R$)
 * @var array       $errors
 * @var array       $old
 * @var bool|null   $required
 */
$type ??= 'text';
$errors ??= [];
$old ??= [];
$error = $errors[$name] ?? null;
$current = $type === 'password' ? '' : (array_key_exists($name, $old) ? $old[$name] : ($value ?? ''));
$hint ??= null;
$describedBy = trim(($hint ? "{$name}-hint " : '') . ($error ? "{$name}-error" : ''));
?>
<div class="field<?= $error ? ' field--invalid' : '' ?>">
    <label for="<?= e($name) ?>"><?= e($label) ?><?php if (!($required ?? true)): ?> <span class="field__optional">(opcional)</span><?php endif ?></label>
    <div class="field__control<?= !empty($suffix) ? ' field__control--suffix' : '' ?><?= !empty($prefix) ? ' field__control--prefix' : '' ?>">
        <?php if (!empty($prefix)): ?><span class="field__prefix" aria-hidden="true"><?= e($prefix) ?></span><?php endif ?>
        <input
            id="<?= e($name) ?>"
            name="<?= e($name) ?>"
            type="<?= e($type) ?>"
            value="<?= e($current) ?>"
            <?php if (!empty($autocomplete)): ?>autocomplete="<?= e($autocomplete) ?>"<?php endif ?>
            <?php if (!empty($placeholder)): ?>placeholder="<?= e($placeholder) ?>"<?php endif ?>
            <?php if (!empty($inputmode)): ?>inputmode="<?= e($inputmode) ?>"<?php endif ?>
            <?php if (!empty($maxlength)): ?>maxlength="<?= e($maxlength) ?>"<?php endif ?>
            <?php if ($required ?? true): ?>required<?php endif ?>
            <?php if ($error): ?>aria-invalid="true"<?php endif ?>
            <?php if ($describedBy !== ''): ?>aria-describedby="<?= e($describedBy) ?>"<?php endif ?>
        >
        <?php if (!empty($suffix)): ?><span class="field__suffix" aria-hidden="true"><?= e($suffix) ?></span><?php endif ?>
    </div>
    <?php if ($hint): ?><small id="<?= e($name) ?>-hint" class="field__hint"><?= e($hint) ?></small><?php endif ?>
    <?php if ($error): ?><p id="<?= e($name) ?>-error" class="field__error"><?= e($error) ?></p><?php endif ?>
</div>
