<?php
/**
 * @var array<string, mixed> $product
 * @var list<array<string, mixed>> $rules com 'values'
 * @var int $imageCount
 * @var int $variantCount
 * @var int $ruleCount
 */
use GNesting\Enums\PersonalizationType;
use GNesting\Services\PersonalizationService;

$base = "/admin/produtos/{$product['id']}/personalizacao";
$describe = static function (array $rule): string {
    return match ($rule['type']) {
        'text' => "{$rule['min_length']} a {$rule['max_length']} caracteres · " . (PersonalizationService::CHARSETS[$rule['charset']][0] ?? ''),
        'initial' => 'até ' . (int) $rule['max_length'] . ' letra(s)',
        'date' => 'dd/mm/aaaa',
        'select' => implode(', ', array_map(
            static fn (array $v): string => $v['label'] . ((int) $v['price_delta_cents'] > 0 ? ' (+' . money((int) $v['price_delta_cents']) . ')' : ''),
            array_filter($rule['values'], static fn (array $v): bool => (bool) $v['is_active'])
        )),
        default => '',
    };
};
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url('/admin/produtos')) ?>">← Produtos</a>
        <h1 class="page-title"><?= e($product['name']) ?></h1>
    </div>
    <?= $this->partial('admin/products/tabs', ['product' => $product, 'active' => 'personalizacao'] + compact('imageCount', 'variantCount', 'ruleCount')) ?>
</div>

<section class="panel">
    <div class="option-block__head">
        <h2 class="panel__title">Campos de personalização</h2>
        <a class="btn btn--primary btn--sm" href="<?= e(url("{$base}/novo")) ?>">Novo campo</a>
    </div>
    <p class="muted">O cliente só preenche o que estiver aqui, dentro dos limites definidos. O acréscimo é somado ao preço de cada unidade.</p>

    <?php if ($rules === []): ?>
        <p class="muted">Este produto não aceita personalização.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Campo</th><th>Tipo</th><th>Regras</th><th class="table__num">Acréscimo</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rules as $rule): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url("{$base}/{$rule['id']}/editar")) ?>"><?= e($rule['label']) ?></a>
                            <?php if ($rule['is_required']): ?><span class="badge badge--accent">Obrigatório</span><?php endif ?>
                            <br><code class="muted"><?= e($rule['field_key']) ?></code>
                        </td>
                        <td><?= e(PersonalizationType::tryFrom($rule['type'])?->label() ?? $rule['type']) ?></td>
                        <td><?= e($describe($rule)) ?></td>
                        <td class="table__num nowrap"><?= (int) $rule['price_delta_cents'] > 0 ? '+' . e(money((int) $rule['price_delta_cents'])) : '—' ?></td>
                        <td><?= $rule['is_active'] ? '<span class="status status--on">Ativo</span>' : '<span class="status status--off">Inativo</span>' ?></td>
                        <td class="table__actions">
                            <a class="btn btn--secondary btn--sm" href="<?= e(url("{$base}/{$rule['id']}/editar")) ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>
