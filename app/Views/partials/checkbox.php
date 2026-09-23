<?php
/**
 * Caixa de marcação. Depois de um envio com erro, reflete o que o usuário marcou.
 *
 * @var string      $name
 * @var string      $label
 * @var bool        $checked
 * @var string|null $hint
 * @var array       $old
 */
$old ??= [];
$isChecked = $old !== [] ? array_key_exists($name, $old) : (bool) ($checked ?? false);
?>
<div class="checkbox">
    <input type="checkbox" id="<?= e($name) ?>" name="<?= e($name) ?>" value="1"<?= $isChecked ? ' checked' : '' ?>>
    <label for="<?= e($name) ?>">
        <?= e($label) ?>
        <?php if (!empty($hint)): ?><small class="field__hint"><?= e($hint) ?></small><?php endif ?>
    </label>
</div>
