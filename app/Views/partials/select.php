<?php
/**
 * @var string                     $name
 * @var string                     $label
 * @var array<string|int, string>  $options valor => rótulo
 * @var mixed                      $value
 * @var string|null                $placeholder opção vazia no topo
 * @var string|null                $hint
 * @var array                      $errors
 * @var array                      $old
 * @var bool|null                  $required
 */
$errors ??= [];
$old ??= [];
$error = $errors[$name] ?? null;
$current = (string) (array_key_exists($name, $old) ? $old[$name] : ($value ?? ''));
$hint ??= null;
$describedBy = trim(($hint ? "{$name}-hint " : '') . ($error ? "{$name}-error" : ''));
?>
<div class="field<?= $error ? ' field--invalid' : '' ?>">
    <label for="<?= e($name) ?>"><?= e($label) ?><?php if (!($required ?? true)): ?> <span class="field__optional">(opcional)</span><?php endif ?></label>
    <select
        id="<?= e($name) ?>"
        name="<?= e($name) ?>"
        <?php if ($required ?? true): ?>required<?php endif ?>
        <?php if ($error): ?>aria-invalid="true"<?php endif ?>
        <?php if ($describedBy !== ''): ?>aria-describedby="<?= e($describedBy) ?>"<?php endif ?>
    >
        <?php if (isset($placeholder)): ?><option value=""><?= e($placeholder) ?></option><?php endif ?>
        <?php foreach ($options as $optionValue => $optionLabel): ?>
            <option value="<?= e($optionValue) ?>"<?= (string) $optionValue === $current ? ' selected' : '' ?>><?= e($optionLabel) ?></option>
        <?php endforeach ?>
    </select>
    <?php if ($hint): ?><small id="<?= e($name) ?>-hint" class="field__hint"><?= e($hint) ?></small><?php endif ?>
    <?php if ($error): ?><p id="<?= e($name) ?>-error" class="field__error"><?= e($error) ?></p><?php endif ?>
</div>
