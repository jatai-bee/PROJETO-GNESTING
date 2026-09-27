<?php
/**
 * Cadastro do produto em abas: Geral, Comercial, Estoque e envio e SEO pertencem a um único formulário
 * (sem JavaScript as seções aparecem uma embaixo da outra). Variações, Imagens, Personalização e
 * Produção são telas próprias, nas mesmas abas.
 * @var array<string, mixed>|null $product
 * @var list<array<string, mixed>> $categories
 * @var string|null $cover
 * @var int $imageCount
 * @var int $variantCount
 * @var int $ruleCount
 * @var array $errors
 * @var array $old
 */
$f = fn (string $partial, array $vars): string => $this->partial($partial, $vars + ['errors' => $errors, 'old' => $old]);
$p = $product ?? [];
$action = $product ? "/admin/produtos/{$product['id']}/editar" : '/admin/produtos/novo';

$categoryOptions = [];
foreach ($categories as $category) {
    $categoryOptions[$category['id']] = ($category['parent_id'] ? '— ' : '') . $category['name'] . ($category['is_active'] ? '' : ' (inativa)');
}
$dimension = fn (string $name, string $label, string $suffix): string => $f('partials/field', [
    'name' => $name, 'label' => $label, 'value' => $p[$name] ?? '', 'required' => false,
    'inputmode' => 'numeric', 'suffix' => $suffix,
]);
$price = isset($p['price_cents']) ? (int) $p['price_cents'] : null;
$cost = isset($p['cost_cents']) ? (int) $p['cost_cents'] : null;
$margin = $price !== null && $price > 0 && $cost !== null ? (int) round(($price - $cost) * 100 / $price) : null;
$icon = fn (string $name, int $size = 16): string => $this->partial('partials/icon', ['name' => $name, 'size' => $size]);
$storeUrl = $product ? url('/produto/' . $product['slug']) : null;
?>
<div class="product-head">
    <div class="product-head__main">
        <span class="product-head__thumb">
            <?php if (!empty($cover)): ?>
                <img src="<?= e(upload_url($cover, 400)) ?>" alt="" width="72" height="72">
            <?php else: ?>
                <?= $icon('tag', 26) ?>
            <?php endif ?>
        </span>
        <div>
            <h1 class="page-title"><?= $product ? e($product['name']) : 'Novo produto' ?></h1>
            <?php if ($product): ?>
                <p class="product-head__meta">
                    <span class="status status--<?= $product['is_active'] ? 'on' : 'off' ?>"><?= $product['is_active'] ? 'Ativo na loja' : 'Inativo' ?></span>
                    <span class="mono"><?= e($product['sku']) ?></span>
                    <span><?= e(money((int) $product['price_cents'])) ?></span>
                    <?php if ($margin !== null): ?><span class="<?= $margin < 20 ? 'text-warn' : 'muted' ?>">margem <?= e($margin) ?>%</span><?php endif ?>
                </p>
            <?php else: ?>
                <p class="muted">O produto nasce inativo. Depois de salvar, envie as fotos e ative.</p>
            <?php endif ?>
        </div>
    </div>
    <?php if ($product): ?>
    <div class="product-head__actions">
        <?php if ($product['is_active']): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e($storeUrl) ?>" target="_blank" rel="noopener"><?= $icon('external') ?> Ver na loja</a>
        <?php endif ?>
        <form method="post" action="<?= e(url("/admin/produtos/{$product['id']}/duplicar")) ?>" class="inline-form"
              data-confirm="Criar uma cópia inativa de &quot;<?= e($product['name']) ?>&quot; (sem fotos)?">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--secondary btn--sm">Duplicar</button>
        </form>
        <form method="post" action="<?= e(url("/admin/produtos/{$product['id']}/status")) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="active" value="<?= $product['is_active'] ? '0' : '1' ?>">
            <button type="submit" class="btn btn--<?= $product['is_active'] ? 'secondary' : 'primary' ?> btn--sm"><?= $product['is_active'] ? 'Desativar' : 'Ativar produto' ?></button>
        </form>
    </div>
    <?php endif ?>
</div>

<?php if ($product && !$product['is_active'] && $imageCount === 0): ?>
    <div class="alert alert--info"><p>Para ativar, <a href="<?= e(url("/admin/produtos/{$product['id']}/imagens")) ?>">envie pelo menos uma imagem</a>.</p></div>
<?php endif ?>

<?= $this->partial('admin/products/tabs', ['product' => $product, 'active' => 'geral'] + compact('imageCount', 'variantCount', 'ruleCount')) ?>

<form method="post" action="<?= e(url($action)) ?>" class="form-layout product-form" novalidate data-tabs>
    <?= csrf_field() ?>

    <div class="tab-panel" id="geral" data-tab-panel="geral">
        <section class="panel">
            <h2 class="panel__title">Apresentação</h2>
            <?= $f('partials/field', ['name' => 'name', 'label' => 'Nome do produto', 'value' => $p['name'] ?? '', 'maxlength' => 150]) ?>
            <?= $f('partials/select', ['name' => 'category_id', 'label' => 'Categoria', 'options' => $categoryOptions, 'value' => $p['category_id'] ?? '', 'placeholder' => 'Selecione...']) ?>
            <?= $f('partials/textarea', ['name' => 'short_description', 'label' => 'Resumo', 'value' => $p['short_description'] ?? '', 'rows' => 2, 'maxlength' => 300,
                'hint' => 'Uma frase que aparece no cartão da vitrine e no topo da ficha. Até 300 caracteres.']) ?>
            <?= $f('partials/textarea', ['name' => 'description', 'label' => 'Descrição', 'value' => $p['description'] ?? '', 'rows' => 6, 'maxlength' => 10000,
                'hint' => 'Deixe uma linha em branco para começar um novo parágrafo.']) ?>
            <?= $f('partials/textarea', ['name' => 'highlights', 'label' => 'Características', 'value' => $p['highlights'] ?? '', 'rows' => 4, 'maxlength' => 2000,
                'hint' => 'Uma por linha. Ex.: "Máquina de ponteiro silenciosa".']) ?>
            <div class="checkbox-group">
                <?= $f('partials/checkbox', ['name' => 'is_featured', 'label' => 'Destaque na página inicial', 'checked' => (bool) ($p['is_featured'] ?? false)]) ?>
                <?= $f('partials/checkbox', ['name' => 'is_new', 'label' => 'Lançamento (selo "Novo")', 'checked' => (bool) ($p['is_new'] ?? false)]) ?>
            </div>
        </section>
        <section class="panel">
            <h2 class="panel__title">Material e medidas <small class="muted">(exibidos na ficha do produto)</small></h2>
            <div class="form-grid">
                <?= $f('partials/field', ['name' => 'material_label', 'label' => 'Material', 'value' => $p['material_label'] ?? '', 'required' => false, 'placeholder' => 'MDF amadeirado 6 mm']) ?>
                <?= $f('partials/field', ['name' => 'finish_label', 'label' => 'Acabamento', 'value' => $p['finish_label'] ?? '', 'required' => false, 'placeholder' => 'Natural']) ?>
                <?= $dimension('width_mm', 'Largura', 'mm') ?>
                <?= $dimension('height_mm', 'Altura', 'mm') ?>
                <?= $dimension('depth_mm', 'Profundidade', 'mm') ?>
                <?= $dimension('weight_g', 'Peso', 'g') ?>
            </div>
        </section>
        <section class="panel">
            <h2 class="panel__title">Montagem e cuidados</h2>
            <div class="form-grid form-grid--2">
                <?= $f('partials/textarea', ['name' => 'assembly_info', 'label' => 'Montagem', 'value' => $p['assembly_info'] ?? '', 'rows' => 3, 'maxlength' => 2000, 'required' => false,
                    'hint' => 'Ex.: "Chega montado. Fixação com 2 parafusos (inclusos)."']) ?>
                <?= $f('partials/textarea', ['name' => 'care_instructions', 'label' => 'Cuidados', 'value' => $p['care_instructions'] ?? '', 'rows' => 3, 'maxlength' => 2000, 'required' => false,
                    'hint' => 'Ex.: "Limpe com pano seco. Evite umidade e sol direto."']) ?>
            </div>
        </section>
    </div>

    <div class="tab-panel" id="comercial" data-tab-panel="comercial">
        <section class="panel">
            <h2 class="panel__title">Preço, custo e margem</h2>
            <?php if ($product && $variantCount > 1): ?>
                <div class="alert alert--info"><p>Estes valores são da <strong>variação padrão</strong>. As outras <?= e($variantCount - 1) ?> têm preço e custo próprios na aba <a href="<?= e(url("/admin/produtos/{$product['id']}/variantes")) ?>">Variações</a>.</p></div>
            <?php endif ?>
            <div class="margin-layout" data-margin>
            <div class="form-grid">
                <?= $f('partials/field', ['name' => 'price', 'label' => 'Preço de venda', 'value' => money_input($price),
                    'inputmode' => 'decimal', 'placeholder' => '129,90', 'prefix' => 'R$']) ?>
                <?= $f('partials/field', ['name' => 'compare_at_price', 'label' => 'Preço "de" (promoção)', 'required' => false,
                    'value' => money_input(isset($p['compare_at_price_cents']) ? (int) $p['compare_at_price_cents'] : null),
                    'inputmode' => 'decimal', 'prefix' => 'R$', 'hint' => 'Aparece riscado, com o selo de desconto. Maior que o preço de venda.']) ?>
                <?= $f('partials/field', ['name' => 'cost', 'label' => 'Custo unitário', 'required' => false, 'value' => money_input($cost),
                    'inputmode' => 'decimal', 'prefix' => 'R$', 'hint' => 'Material, mão de obra e embalagem. Só aparece no painel.']) ?>
            </div>
                <div class="margin-box" aria-live="polite">
                    <span class="margin-box__label">Margem</span>
                    <strong class="margin-box__value" data-margin-value><?= $margin !== null ? e($margin) . '%' : '—' ?></strong>
                    <small class="muted" data-margin-note><?= $margin !== null ? e(money($price - $cost)) . ' por unidade' : 'informe preço e custo' ?></small>
                </div>
            </div>
        </section>
        <section class="panel">
            <h2 class="panel__title">Identificação</h2>
            <div class="form-grid form-grid--2">
                <?= $f('partials/field', ['name' => 'sku', 'label' => 'SKU', 'value' => $p['sku'] ?? '', 'maxlength' => 40, 'placeholder' => 'REL-GEO-001',
                    'hint' => 'Código único e permanente, usado na produção e no estoque.']) ?>
            </div>
        </section>
    </div>

    <div class="tab-panel" id="estoque" data-tab-panel="estoque">
        <section class="panel">
            <h2 class="panel__title">Estoque</h2>
            <div class="form-grid form-grid--2">
                <?= $f('partials/select', ['name' => 'stock_mode', 'label' => 'Modo', 'value' => $p['stock_mode'] ?? 'made_to_order', 'options' => [
                    'made_to_order' => 'Produzido sob pedido',
                    'stock' => 'Pronta entrega (controla quantidade)',
                ]]) ?>
                <?= $f('partials/field', ['name' => 'quantity_on_hand', 'label' => 'Quantidade em estoque', 'value' => $p['quantity_on_hand'] ?? 0,
                    'required' => false, 'inputmode' => 'numeric', 'suffix' => 'un.', 'hint' => 'Usada apenas em "Pronta entrega". Alterações ficam registradas.']) ?>
            </div>
            <?php if (!empty($p['quantity_reserved'])): ?>
                <p class="muted">Reservado em pedidos: <?= e($p['quantity_reserved']) ?> un.</p>
            <?php endif ?>
        </section>
        <section class="panel">
            <h2 class="panel__title">Prazos</h2>
            <div class="form-grid form-grid--2">
                <?= $f('partials/field', ['name' => 'production_lead_days', 'label' => 'Prazo de produção', 'value' => $p['production_lead_days'] ?? 3,
                    'inputmode' => 'numeric', 'suffix' => 'dias úteis', 'hint' => 'Do pagamento até a peça ficar pronta (sob pedido).']) ?>
                <?= $f('partials/field', ['name' => 'dispatch_days', 'label' => 'Prazo de postagem', 'value' => $p['dispatch_days'] ?? 1, 'required' => false,
                    'inputmode' => 'numeric', 'suffix' => 'dias úteis', 'hint' => 'Da peça pronta até entregar à transportadora.']) ?>
            </div>
        </section>
        <section class="panel">
            <h2 class="panel__title">Embalagem <small class="muted">(usada no cálculo de frete)</small></h2>
            <div class="form-grid">
                <?= $dimension('package_width_mm', 'Largura', 'mm') ?>
                <?= $dimension('package_height_mm', 'Altura', 'mm') ?>
                <?= $dimension('package_length_mm', 'Comprimento', 'mm') ?>
                <?= $dimension('package_weight_g', 'Peso total', 'g') ?>
            </div>
        </section>
    </div>

    <div class="tab-panel" id="seo" data-tab-panel="seo">
        <section class="panel">
            <h2 class="panel__title">Como aparece no Google</h2>
            <div class="serp" aria-hidden="true" data-serp>
                <span class="serp__url"><?= e(rtrim((string) config('app.url'), '/')) ?>/produto/<span data-serp-slug><?= e($p['slug'] ?? 'endereco-do-produto') ?></span></span>
                <span class="serp__title" data-serp-title><?= e(($p['meta_title'] ?? '') ?: (($p['name'] ?? 'Nome do produto') . ' | G-Nesting')) ?></span>
                <span class="serp__desc" data-serp-desc><?= e(($p['meta_description'] ?? '') ?: ($p['short_description'] ?? 'O resumo do produto aparece aqui.')) ?></span>
            </div>
            <?= $f('partials/field', ['name' => 'meta_title', 'label' => 'Título', 'value' => $p['meta_title'] ?? '', 'required' => false, 'maxlength' => 70, 'hint' => 'Até 70 caracteres. Vazio = nome do produto.']) ?>
            <?= $f('partials/textarea', ['name' => 'meta_description', 'label' => 'Descrição', 'value' => $p['meta_description'] ?? '', 'rows' => 2, 'maxlength' => 160, 'required' => false, 'hint' => 'Até 160 caracteres. Vazio = resumo.']) ?>
            <?= $f('partials/field', ['name' => 'slug', 'label' => 'Endereço (slug)', 'value' => $p['slug'] ?? '', 'required' => false,
                'hint' => $product
                    ? 'Parte final da URL do produto. Evite alterar: links já divulgados deixam de funcionar.'
                    : 'Parte final da URL: /produto/relogio-geometrico. Deixe vazio para gerar a partir do nome.']) ?>
            <?= $f('partials/field', ['name' => 'keywords', 'label' => 'Palavras-chave da busca', 'value' => $p['keywords'] ?? '', 'required' => false, 'maxlength' => 255,
                'hint' => 'Separadas por vírgula. A busca da loja também encontra o produto por elas. Ex.: "relógio de parede, sala, presente".']) ?>
        </section>
    </div>

    <div class="form-actions form-actions--sticky">
        <button type="submit" class="btn btn--primary"><?= $product ? 'Salvar alterações' : 'Criar produto' ?></button>
        <a class="btn btn--secondary" href="<?= e(url('/admin/produtos')) ?>">Cancelar</a>
    </div>
</form>

<?php if ($product): ?>
    <form method="post" action="<?= e(url("/admin/produtos/{$product['id']}/excluir")) ?>" class="danger-zone"
          data-confirm="Excluir o produto &quot;<?= e($product['name']) ?>&quot;? Ele sai da loja; o histórico de pedidos é preservado.">
        <?= csrf_field() ?>
        <p>Excluir este produto. Ele deixa de aparecer na loja e no painel; o SKU continua reservado.</p>
        <button type="submit" class="btn btn--danger btn--sm">Excluir produto</button>
    </form>
<?php endif ?>
