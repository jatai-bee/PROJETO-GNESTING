<?php
/**
 * @var array<string, mixed>|null $coupon
 * @var array<string, string> $types
 * @var array $errors
 * @var array $old
 */
$f = fn (string $partial, array $vars): string => $this->partial($partial, $vars + ['errors' => $errors, 'old' => $old]);
$c = $coupon ?? [];
$action = $coupon ? '/admin/cupons/' . $coupon['id'] . '/editar' : '/admin/cupons/novo';
$local = static fn (?string $utc): string => $utc === null ? '' : format_datetime($utc, 'Y-m-d\TH:i');
$percent = ($c['type'] ?? '') === 'percent' ? format_decimal(sprintf('%d.%02d', intdiv((int) $c['value'], 100), (int) $c['value'] % 100)) : '';
$fixed = ($c['type'] ?? '') === 'fixed' ? money_input((int) $c['value']) : '';
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url('/admin/cupons')) ?>">← Cupons</a>
        <h1 class="page-title"><?= $coupon ? e($coupon['code']) : 'Novo cupom' ?></h1>
        <?php if ($coupon): ?><p class="muted">Usado <?= e($coupon['times_used']) ?> vez(es).</p><?php endif ?>
    </div>
</div>

<form method="post" action="<?= e(url($action)) ?>" class="form-layout" novalidate>
    <?= csrf_field() ?>
    <section class="panel">
        <h2 class="panel__title">Cupom</h2>
        <div class="form-grid form-grid--2">
            <?= $f('partials/field', ['name' => 'code', 'label' => 'Código', 'value' => $c['code'] ?? '', 'maxlength' => 40, 'placeholder' => 'BEMVINDO10',
                'hint' => 'O cliente digita no carrinho. Letras, números, "-" e "_"; maiúsculas e minúsculas tanto faz.']) ?>
            <?= $f('partials/field', ['name' => 'description', 'label' => 'Descrição interna', 'value' => $c['description'] ?? '', 'required' => false, 'maxlength' => 200]) ?>
        </div>
        <?= $f('partials/checkbox', ['name' => 'is_active', 'label' => 'Ativo', 'checked' => (bool) ($c['is_active'] ?? true)]) ?>
    </section>

    <section class="panel">
        <h2 class="panel__title">Benefício</h2>
        <div class="form-grid">
            <?= $f('partials/select', ['name' => 'type', 'label' => 'Tipo', 'value' => $c['type'] ?? 'percent', 'options' => $types]) ?>
            <?= $f('partials/field', ['name' => 'percent', 'label' => 'Percentual', 'value' => $percent, 'required' => false, 'suffix' => '%', 'inputmode' => 'decimal',
                'hint' => 'Para "Percentual".']) ?>
            <?= $f('partials/field', ['name' => 'max_discount', 'label' => 'Desconto máximo', 'required' => false, 'prefix' => 'R$', 'inputmode' => 'decimal',
                'value' => money_input(isset($c['max_discount_cents']) ? (int) $c['max_discount_cents'] : null), 'hint' => 'Teto do percentual (opcional).']) ?>
            <?= $f('partials/field', ['name' => 'fixed', 'label' => 'Valor do desconto', 'value' => $fixed, 'required' => false, 'prefix' => 'R$', 'inputmode' => 'decimal',
                'hint' => 'Para "Valor fixo".']) ?>
        </div>
        <p class="field__hint">"Frete grátis" zera a opção de entrega mais econômica; se o cliente escolher outra, paga só a diferença.</p>
    </section>

    <section class="panel">
        <h2 class="panel__title">Regras</h2>
        <div class="form-grid">
            <?= $f('partials/field', ['name' => 'min_subtotal', 'label' => 'Compra mínima', 'required' => false, 'prefix' => 'R$', 'inputmode' => 'decimal',
                'value' => money_input(isset($c['min_subtotal_cents']) ? (int) $c['min_subtotal_cents'] : null), 'hint' => 'Subtotal dos produtos, sem frete.']) ?>
            <?= $f('partials/field', ['name' => 'starts_at', 'label' => 'Início', 'type' => 'datetime-local', 'required' => false, 'value' => $local($c['starts_at'] ?? null)]) ?>
            <?= $f('partials/field', ['name' => 'ends_at', 'label' => 'Fim', 'type' => 'datetime-local', 'required' => false, 'value' => $local($c['ends_at'] ?? null)]) ?>
            <?= $f('partials/field', ['name' => 'usage_limit', 'label' => 'Limite de usos', 'hint' => 'Total, somando todos os clientes.', 'required' => false, 'inputmode' => 'numeric', 'value' => $c['usage_limit'] ?? '']) ?>
            <?= $f('partials/field', ['name' => 'usage_limit_per_customer', 'label' => 'Usos por cliente', 'required' => false, 'inputmode' => 'numeric',
                'value' => $c['usage_limit_per_customer'] ?? '', 'hint' => 'Conferido na finalização da compra (pelo e-mail do cliente).']) ?>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary"><?= $coupon ? 'Salvar' : 'Criar cupom' ?></button>
        <a class="btn btn--secondary" href="<?= e(url('/admin/cupons')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($coupon && (int) $coupon['times_used'] === 0): ?>
    <form method="post" action="<?= e(url('/admin/cupons/' . $coupon['id'] . '/excluir')) ?>" class="danger-zone" data-confirm="Excluir o cupom <?= e($coupon['code']) ?>?">
        <?= csrf_field() ?>
        <p>Excluir este cupom (possível só enquanto não foi usado).</p>
        <button type="submit" class="btn btn--danger btn--sm">Excluir cupom</button>
    </form>
<?php endif ?>
