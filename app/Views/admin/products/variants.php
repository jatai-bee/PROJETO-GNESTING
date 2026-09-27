<?php
/**
 * @var array<string, mixed> $product
 * @var list<array<string, mixed>> $options  com 'values' (id, value, in_use)
 * @var list<array<string, mixed>> $variants
 * @var int $imageCount
 * @var int $variantCount
 * @var int $ruleCount
 */
$base = "/admin/produtos/{$product['id']}/variantes";
$canAddOption = count($options) < \GNesting\Services\VariantService::MAX_OPTIONS;
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url('/admin/produtos')) ?>">← Produtos</a>
        <h1 class="page-title"><?= e($product['name']) ?></h1>
    </div>
    <?= $this->partial('admin/products/tabs', ['product' => $product, 'active' => 'variantes'] + compact('imageCount', 'variantCount', 'ruleCount')) ?>
</div>

<section class="panel">
    <h2 class="panel__title">Opções de variação</h2>
    <p class="muted">Eixos que o cliente escolhe na loja, como <em>Acabamento</em> ou <em>Tamanho</em>. O cliente só compra combinações cadastradas abaixo.</p>

    <?php if ($options === []): ?>
        <p class="muted">Este produto é vendido em uma única versão.</p>
    <?php endif ?>

    <?php foreach ($options as $option): ?>
        <div class="option-block">
            <div class="option-block__head">
                <h3 class="panel__subtitle"><?= e($option['name']) ?></h3>
                <?php if (count($option['values']) === 1): ?>
                    <form method="post" action="<?= e(url("{$base}/opcoes/{$option['id']}/excluir")) ?>" class="inline-form"
                          data-confirm="Excluir a opção &quot;<?= e($option['name']) ?>&quot;?">
                        <?= csrf_field() ?>
                        <button type="submit" class="link-button">Excluir opção</button>
                    </form>
                <?php endif ?>
            </div>
            <ul class="chip-list">
                <?php foreach ($option['values'] as $value): ?>
                    <li class="chip-list__item">
                        <?= e($value['value']) ?>
                        <?php if ((int) $value['in_use'] === 0 && count($option['values']) > 1): ?>
                            <form method="post" action="<?= e(url("{$base}/opcoes/{$option['id']}/valores/{$value['id']}/excluir")) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <button type="submit" class="chip-list__remove" aria-label="Excluir <?= e($value['value']) ?>">×</button>
                            </form>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
            <form method="post" action="<?= e(url("{$base}/opcoes/{$option['id']}/valores")) ?>" class="inline-add">
                <?= csrf_field() ?>
                <label class="visually-hidden" for="valor-<?= e($option['id']) ?>">Novo valor para <?= e($option['name']) ?></label>
                <input id="valor-<?= e($option['id']) ?>" name="value" type="text" maxlength="60" placeholder="Novo valor" required>
                <button type="submit" class="btn btn--secondary btn--sm">Adicionar</button>
            </form>
        </div>
    <?php endforeach ?>

    <?php if ($canAddOption): ?>
        <details class="option-new" <?= $options === [] ? 'open' : '' ?>>
            <summary>Nova opção</summary>
            <form method="post" action="<?= e(url("{$base}/opcoes")) ?>">
                <?= csrf_field() ?>
                <div class="form-grid form-grid--2">
                    <div class="field">
                        <label for="option-name">Nome</label>
                        <input id="option-name" name="name" type="text" maxlength="60" placeholder="Acabamento" required>
                    </div>
                    <div class="field">
                        <label for="option-values">Valores</label>
                        <input id="option-values" name="values" type="text" maxlength="700" placeholder="Natural, Preto, Branco" required>
                        <small class="field__hint">Separe por vírgula. As variações existentes recebem o primeiro valor.</small>
                    </div>
                </div>
                <button type="submit" class="btn btn--secondary btn--sm">Criar opção</button>
            </form>
        </details>
    <?php endif ?>
</section>

<section class="panel">
    <div class="option-block__head">
        <h2 class="panel__title">Variações (SKUs)</h2>
        <?php if ($options !== []): ?>
            <form method="post" action="<?= e(url("{$base}/gerar")) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--primary btn--sm">Gerar variações</button>
            </form>
        <?php endif ?>
    </div>
    <p class="muted">Cada variação tem SKU, preço, medidas e estoque próprios. A padrão aparece selecionada na loja; o preço e o estoque dela também ficam nas abas Comercial e Estoque e envio.</p>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr><th>Variação</th><th>SKU</th><th class="table__num">Preço</th><th>Estoque</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr>
            </thead>
            <tbody>
            <?php foreach ($variants as $variant): ?>
                <tr>
                    <td>
                        <?= e($variant['name'] ?? 'Única') ?>
                        <?php if ($variant['is_default']): ?><span class="badge badge--accent">Padrão</span><?php endif ?>
                    </td>
                    <td><code><?= e($variant['sku']) ?></code></td>
                    <td class="table__num nowrap"><?= e(money((int) $variant['price_cents'])) ?></td>
                    <td class="nowrap">
                        <?= $variant['stock_mode'] === 'stock'
                            ? e((int) $variant['quantity_on_hand'] - (int) $variant['quantity_reserved']) . ' un.'
                            : 'Sob pedido' ?>
                    </td>
                    <td><?= $variant['is_active'] ? '<span class="status status--on">Ativa</span>' : '<span class="status status--off">Inativa</span>' ?></td>
                    <td class="table__actions">
                        <?php if ($variant['is_default']): ?>
                            <a class="btn btn--secondary btn--sm" href="<?= e(url("/admin/produtos/{$product['id']}/editar")) ?>">Editar</a>
                        <?php else: ?>
                            <a class="btn btn--secondary btn--sm" href="<?= e(url("{$base}/{$variant['id']}/editar")) ?>">Editar</a>
                            <?php if ($variant['is_active']): ?>
                                <form method="post" action="<?= e(url("{$base}/{$variant['id']}/padrao")) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn--secondary btn--sm">Tornar padrão</button>
                                </form>
                            <?php endif ?>
                            <form method="post" action="<?= e(url("{$base}/{$variant['id']}/excluir")) ?>" class="inline-form"
                                  data-confirm="Excluir a variação <?= e($variant['sku']) ?>? O SKU continua reservado.">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn--danger btn--sm">Excluir</button>
                            </form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>
