<?php
/**
 * Ficha do produto: galeria, preço, disponibilidade, variação, personalização, quantidade,
 * favorito, compartilhar, prazos, descrição, especificações, montagem, cuidados e relacionados.
 * @var array<string, mixed> $product
 * @var list<array<string, mixed>> $variants  ativas, padrão primeiro (com in_stock, max_quantity)
 * @var array<string, mixed> $selected        variação exibida
 * @var string $variantLabel                  ex.: "Acabamento / Tamanho"
 * @var bool $anyInStock
 * @var list<array<string, mixed>> $rules     personalização (com 'values')
 * @var list<array{path: string, alt_text: string}> $images
 * @var list<string> $highlights
 * @var list<array> $related
 * @var list<array> $breadcrumbs
 * @var list<int> $favoriteIds
 * @var string|null $canonical
 * @var array $errors
 * @var array $old
 */
use GNesting\Services\PersonalizationService;

$leadDays = (int) $product['production_lead_days'];
$dispatchDays = (int) ($product['dispatch_days'] ?? 1);
$cm = static fn (float $v): string => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
$days = static fn (int $n): string => $n . ' dia' . ($n === 1 ? ' útil' : 's úteis');
$icon = fn (string $name, int $size = 20): string => $this->partial('partials/icon', ['name' => $name, 'size' => $size]);

/** Textos exibidos de uma variação (servidor e troca via JS usam o mesmo formato). */
$display = static function (array $v) use ($cm, $leadDays, $days): array {
    $price = (int) $v['price_cents'];
    $compare = $v['compare_at_price_cents'] === null ? null : (int) $v['compare_at_price_cents'];
    $dims = array_values(array_filter([$v['width_mm'], $v['height_mm'], $v['depth_mm']], static fn ($d) => $d !== null));
    $available = (int) $v['available'];

    if (!$v['in_stock']) {
        $stock = 'Esgotado no momento.';
        $stockClass = 'out';
    } elseif ($v['stock_mode'] === 'stock') {
        $stock = 'Pronta entrega.' . ($available <= 5 ? " Restam {$available} unidade" . ($available > 1 ? 's' : '') . '.' : '');
        $stockClass = 'ok';
    } else {
        $stock = 'Produzido sob encomenda: fica pronto em até ' . $days($leadDays) . ' antes do envio.';
        $stockClass = 'order';
    }

    return [
        'price' => money($price),
        'compare' => $compare === null ? '' : money($compare),
        'discount' => $compare === null ? '' : (int) round(($compare - $price) * 100 / $compare) . '% off',
        'sku' => (string) $v['sku'],
        'material' => (string) ($v['material_label'] ?? ''),
        'finish' => (string) ($v['finish_label'] ?? ''),
        'dims' => $dims === [] ? '' : implode(' × ', array_map(static fn ($d) => $cm($d / 10), $dims)) . ' cm',
        'weight' => $v['weight_g'] === null ? '' : ((int) $v['weight_g'] >= 1000 ? $cm($v['weight_g'] / 1000) . ' kg' : (int) $v['weight_g'] . ' g'),
        'stock' => $stock,
        'stock_class' => $stockClass,
        'in_stock' => (bool) $v['in_stock'],
        'max' => max(1, (int) $v['max_quantity']),
    ];
};
$shown = $display($selected);
$fieldError = static fn (string $name): ?string => $errors[$name] ?? null;
$oldValue = static fn (string $name, string $default = ''): string => (string) ($old[$name] ?? $default);
$isFavorite = in_array((int) $product['id'], $favoriteIds ?? [], true);
$paragraphs = static fn (?string $text): array => $text === null || trim($text) === '' ? [] : (preg_split('/\R{2,}/', trim($text)) ?: []);
$isNew = (int) ($product['is_new'] ?? 0) === 1;
?>
<div class="container">
    <?= $this->partial('partials/breadcrumbs', ['breadcrumbs' => $breadcrumbs]) ?>

    <article class="product-page">
        <div class="gallery" data-gallery>
            <?php if ($images === []): ?>
                <div class="gallery__main"><span class="media-placeholder media-placeholder--large"><img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="96" height="96"></span></div>
            <?php else: ?>
                <figure class="gallery__main">
                    <img src="<?= e(upload_url($images[0]['path'], 1600)) ?>"
                         srcset="<?= e(upload_url($images[0]['path'], 800)) ?> 800w, <?= e(upload_url($images[0]['path'], 1600)) ?> 1600w"
                         sizes="(min-width: 1000px) 52vw, 100vw"
                         alt="<?= e($images[0]['alt_text']) ?>" width="1600" height="1600" fetchpriority="high" data-gallery-main>
                    <span class="gallery__badges">
                        <?php if ($isNew): ?><span class="tag">Novo</span><?php endif ?>
                    </span>
                </figure>
                <?php if (count($images) > 1): ?>
                <ul class="gallery__thumbs" aria-label="Mais fotos">
                    <?php foreach ($images as $index => $image): ?>
                        <li>
                            <a href="<?= e(upload_url($image['path'], 1600)) ?>" class="gallery__thumb"
                               data-gallery-thumb data-src="<?= e(upload_url($image['path'], 1600)) ?>"
                               data-srcset="<?= e(upload_url($image['path'], 800)) ?> 800w, <?= e(upload_url($image['path'], 1600)) ?> 1600w"
                               data-alt="<?= e($image['alt_text']) ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>">
                                <img src="<?= e(upload_url($image['path'], 400)) ?>" alt="Foto <?= e($index + 1) ?>: <?= e($image['alt_text']) ?>" width="400" height="400" loading="lazy">
                            </a>
                        </li>
                    <?php endforeach ?>
                </ul>
                <?php endif ?>
            <?php endif ?>
        </div>

        <div class="product-info" data-variant-scope>
            <a class="product-info__category" href="<?= e(url('/categoria/' . $product['category_slug'])) ?>"><?= e($product['category_name']) ?></a>
            <h1 class="product-info__title"><?= e($product['name']) ?></h1>
            <p class="product-info__meta">
                <span>Código <span class="mono" data-field="sku"><?= e($shown['sku']) ?></span></span>
                <span class="stock-line stock-line--<?= e($shown['stock_class']) ?>" data-stock-line>
                    <?= $icon($shown['stock_class'] === 'ok' ? 'check' : ($shown['stock_class'] === 'out' ? 'close' : 'clock'), 16) ?>
                    <?= $shown['stock_class'] === 'ok' ? 'Pronta entrega' : ($shown['stock_class'] === 'out' ? 'Esgotado' : 'Sob encomenda') ?>
                </span>
            </p>
            <?php if (!empty($product['short_description'])): ?>
                <p class="product-info__lead"><?= e($product['short_description']) ?></p>
            <?php endif ?>

            <div class="price-box">
                <p class="price-box__row" aria-live="polite">
                    <s class="price-box__old" data-show="compare" <?= $shown['compare'] === '' ? 'hidden' : '' ?>><span class="visually-hidden">De </span><span data-field="compare"><?= e($shown['compare']) ?></span></s>
                    <span class="price-box__current" data-field="price"><?= e($shown['price']) ?></span>
                    <span class="tag tag--accent" data-field="discount" data-show="discount" <?= $shown['discount'] === '' ? 'hidden' : '' ?>><?= e($shown['discount']) ?></span>
                </p>
                <p class="price-box__note">No Pix, cartão ou boleto. Frete calculado no carrinho.</p>
            </div>

            <?php if (!$anyInStock): ?>
                <p class="alert alert--warn" role="status">Produto esgotado no momento.</p>
            <?php else: ?>
                <form class="buy-box" method="post" action="<?= e(url('/carrinho/itens')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e('/produto/' . $product['slug']) ?>">

                    <?php if (count($variants) > 1): ?>
                        <div class="field option-field">
                            <label for="variant_id"><?= e($variantLabel) ?></label>
                            <select id="variant_id" name="variant_id" data-variant-select>
                                <?php foreach ($variants as $variant): ?>
                                    <?php $data = $display($variant); ?>
                                    <option value="<?= e($variant['id']) ?>" data-variant="<?= e(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>"
                                        <?= (int) $variant['id'] === (int) $selected['id'] ? 'selected' : '' ?>
                                        <?= !$variant['in_stock'] && (int) $variant['id'] !== (int) $selected['id'] ? 'disabled' : '' ?>>
                                        <?= e($variant['name'] ?? $variant['sku']) ?> — <?= e($data['price']) ?><?= $variant['in_stock'] ? '' : ' (esgotado)' ?>
                                    </option>
                                <?php endforeach ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="variant_id" value="<?= e($selected['id']) ?>">
                    <?php endif ?>

                    <?php if ($rules !== []): ?>
                        <fieldset class="personalize">
                            <legend>Personalize <small>· opções definidas pelo ateliê</small></legend>
                            <?php foreach ($rules as $rule): ?>
                                <?php
                                $name = PersonalizationService::FIELD_PREFIX . $rule['id'];
                                $error = $fieldError($name);
                                $delta = (int) $rule['price_delta_cents'];
                                $hintId = $name . '-hint';
                                $describedBy = trim((!empty($rule['help_text']) ? $hintId : '') . ($error ? " {$name}-error" : ''));
                                ?>
                                <div class="field<?= $error ? ' field--invalid' : '' ?>">
                                    <label for="<?= e($name) ?>">
                                        <?= e($rule['label']) ?>
                                        <?php if (!$rule['is_required']): ?><span class="field__optional">(opcional)</span><?php endif ?>
                                        <?php if ($delta > 0): ?><span class="price-delta">+ <?= e(money($delta)) ?></span><?php endif ?>
                                    </label>
                                    <?php if ($rule['type'] === 'select'): ?>
                                        <select id="<?= e($name) ?>" name="<?= e($name) ?>" <?= $rule['is_required'] ? 'required' : '' ?>
                                            <?= $error ? 'aria-invalid="true"' : '' ?> <?= $describedBy !== '' ? 'aria-describedby="' . e($describedBy) . '"' : '' ?>>
                                            <option value=""><?= $rule['is_required'] ? 'Escolha...' : 'Sem personalização' ?></option>
                                            <?php foreach ($rule['values'] as $value): ?>
                                                <option value="<?= e($value['id']) ?>" <?= $oldValue($name) === (string) $value['id'] ? 'selected' : '' ?>>
                                                    <?= e($value['label']) ?><?= (int) $value['price_delta_cents'] > 0 ? ' (+ ' . e(money((int) $value['price_delta_cents'])) . ')' : '' ?>
                                                </option>
                                            <?php endforeach ?>
                                        </select>
                                    <?php else: ?>
                                        <input id="<?= e($name) ?>" name="<?= e($name) ?>"
                                               type="<?= $rule['type'] === 'date' ? 'date' : 'text' ?>"
                                               value="<?= e($oldValue($name)) ?>"
                                               <?php if ($rule['type'] !== 'date' && $rule['max_length'] !== null): ?>maxlength="<?= e($rule['max_length']) ?>"<?php endif ?>
                                               <?php if ($rule['type'] === 'initial'): ?>autocapitalize="characters" class="input--upper"<?php endif ?>
                                               <?= $rule['type'] === 'date' ? 'min="1900-01-01" max="2100-12-31"' : 'autocomplete="off" spellcheck="false"' ?>
                                               <?= $rule['is_required'] ? 'required' : '' ?>
                                               <?= $error ? 'aria-invalid="true"' : '' ?>
                                               <?= $describedBy !== '' ? 'aria-describedby="' . e($describedBy) . '"' : '' ?>>
                                    <?php endif ?>
                                    <?php if (!empty($rule['help_text'])): ?><small id="<?= e($hintId) ?>" class="field__hint"><?= e($rule['help_text']) ?></small><?php endif ?>
                                    <?php if ($error): ?><p id="<?= e($name) ?>-error" class="field__error"><?= e($error) ?></p><?php endif ?>
                                </div>
                            <?php endforeach ?>
                            <p class="field__hint">Confira a grafia: a personalização é produzida exatamente como digitada. O acréscimo entra no preço de cada unidade.</p>
                        </fieldset>
                    <?php endif ?>

                    <div class="buy-box__row">
                        <div class="stepper" data-stepper>
                            <label for="quantity" class="visually-hidden">Quantidade</label>
                            <button type="button" class="stepper__button" data-step="-1" aria-label="Diminuir quantidade"><?= $icon('minus', 16) ?></button>
                            <input id="quantity" type="number" name="quantity" value="<?= e($oldValue('quantity', '1')) ?>" min="1"
                                   max="<?= e($shown['max']) ?>" inputmode="numeric" required data-field-max>
                            <button type="button" class="stepper__button" data-step="1" aria-label="Aumentar quantidade"><?= $icon('plus', 16) ?></button>
                        </div>
                        <button type="submit" class="btn btn--primary btn--lg buy-box__submit" data-buy <?= $shown['in_stock'] ? '' : 'disabled' ?>>
                            <?= $icon('bag', 20) ?> Adicionar ao carrinho
                        </button>
                    </div>
                </form>
                <p class="stock-note field__hint" data-field="stock"><?= e($shown['stock']) ?></p>
            <?php endif ?>

            <div class="buy-box__secondary">
                <form method="post" action="<?= e(url('/favoritos/' . $product['id'])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="voltar" value="<?= e('/produto/' . $product['slug']) ?>">
                    <button type="submit" class="btn btn--ghost btn--sm" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>">
                        <?= $icon('heart', 18) ?> <?= $isFavorite ? 'Nos seus favoritos' : 'Favoritar' ?>
                    </button>
                </form>
                <button type="button" class="btn btn--ghost btn--sm" data-share data-share-url="<?= e($canonical ?? '') ?>" data-share-title="<?= e($product['name']) ?>" hidden>
                    <?= $icon('share', 18) ?> <span data-share-label>Compartilhar</span>
                </button>
                <?php if (!empty($whatsappUrl)): ?>
                    <a class="btn btn--ghost btn--sm" href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener noreferrer">Dúvidas? WhatsApp</a>
                <?php endif ?>
            </div>

            <ul class="delivery-list">
                <li><?= $icon('clock') ?><div><strong><?= ($selected['stock_mode'] ?? '') === 'stock' ? 'Pronta entrega' : 'Produção em até ' . e($days($leadDays)) ?></strong>
                    <?= ($selected['stock_mode'] ?? '') === 'stock' ? 'Separado e embalado' : 'Cortado e acabado depois da confirmação do pagamento' ?>; postagem em até <?= e($days($dispatchDays)) ?>.</div></li>
                <li><?= $icon('truck') ?><div><strong>Entrega para todo o Brasil</strong>Calcule o frete e o prazo no carrinho, com o seu CEP.</div></li>
                <?php if ($rules !== []): ?>
                <li><?= $icon('pencil') ?><div><strong>Personalização conferida</strong>Revisamos cada texto antes de gravar.</div></li>
                <?php endif ?>
                <li><?= $icon('shield') ?><div><strong>Troca garantida</strong>Até 7 dias após o recebimento. <a href="<?= e(url('/trocas-e-devolucoes')) ?>">Ver política</a></div></li>
            </ul>

            <?php if ($highlights !== []): ?>
                <ul class="highlights">
                    <?php foreach ($highlights as $highlight): ?>
                        <li><?= e($highlight) ?></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    </article>

    <section class="product-details" aria-label="Detalhes do produto">
        <div class="card">
            <?php if ($paragraphs($product['description'] ?? null) !== []): ?>
                <h2 id="descricao">Sobre o produto</h2>
                <div class="prose">
                    <?php foreach ($paragraphs($product['description']) as $paragraph): ?>
                        <p><?= nl2br(e($paragraph), false) ?></p>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
            <?php if ($paragraphs($product['assembly_info'] ?? null) !== []): ?>
                <h3>Montagem</h3>
                <div class="prose">
                    <?php foreach ($paragraphs($product['assembly_info']) as $paragraph): ?><p><?= nl2br(e($paragraph), false) ?></p><?php endforeach ?>
                </div>
            <?php endif ?>
            <?php if ($paragraphs($product['care_instructions'] ?? null) !== []): ?>
                <h3>Cuidados</h3>
                <div class="prose">
                    <?php foreach ($paragraphs($product['care_instructions']) as $paragraph): ?><p><?= nl2br(e($paragraph), false) ?></p><?php endforeach ?>
                </div>
            <?php endif ?>
        </div>
        <div class="card">
            <h2>Especificações</h2>
            <dl class="spec-table">
                <?php foreach (['material' => 'Material', 'finish' => 'Acabamento', 'dims' => 'Medidas (L × A × P)', 'weight' => 'Peso'] as $key => $label): ?>
                    <div data-show="<?= e($key) ?>" <?= $shown[$key] === '' ? 'hidden' : '' ?>>
                        <dt><?= e($label) ?></dt>
                        <dd data-field="<?= e($key) ?>"><?= e($shown[$key]) ?></dd>
                    </div>
                <?php endforeach ?>
                <div><dt>Produção</dt><dd><?= ($selected['stock_mode'] ?? '') === 'stock' ? 'Pronta entrega' : 'Sob encomenda, até ' . e($days($leadDays)) ?></dd></div>
                <div><dt>Personalização</dt><dd><?= $rules !== [] ? 'Disponível' : 'Não se aplica' ?></dd></div>
                <div><dt>Código</dt><dd class="mono" data-field="sku"><?= e($shown['sku']) ?></dd></div>
            </dl>
        </div>
    </section>

    <?php if ($related !== []): ?>
    <section class="section section--tight" aria-labelledby="relacionados">
        <div class="section__head">
            <h2 id="relacionados" class="section__title">Você também pode gostar</h2>
            <a class="link-arrow" href="<?= e(url('/categoria/' . $product['category_slug'])) ?>">Mais em <?= e($product['category_name']) ?></a>
        </div>
        <div class="product-grid">
            <?php foreach ($related as $item): ?>
                <?= $this->partial('partials/product-card', ['product' => $item]) ?>
            <?php endforeach ?>
        </div>
    </section>
    <?php endif ?>
</div>
