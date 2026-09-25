<?php
/**
 * @var array<string, mixed> $product
 * @var list<array<string, mixed>> $images
 * @var int $maxImages
 * @var int $maxMb
 */
$base = "/admin/produtos/{$product['id']}/imagens";
$movable = array_values(array_filter($images, static fn (array $i) => !$i['is_cover']));
$movableIds = array_column($movable, 'id');
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url('/admin/produtos')) ?>">← Produtos</a>
        <h1 class="page-title"><?= e($product['name']) ?></h1>
    </div>
    <?= $this->partial('admin/products/tabs', ['product' => $product, 'active' => 'imagens', 'imageCount' => count($images)]) ?>
</div>

<?php if (!$product['is_active']): ?>
    <section class="status-bar status-bar--off">
        <div><strong>Produto inativo.</strong>
            <?= $images === [] ? 'Envie pelo menos uma imagem para poder ativá-lo.' : 'Quando as imagens estiverem prontas, ative o produto.' ?>
        </div>
        <?php if ($images !== []): ?>
            <form method="post" action="<?= e(url("/admin/produtos/{$product['id']}/status")) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="active" value="1">
                <input type="hidden" name="back" value="<?= e($base) ?>">
                <button type="submit" class="btn btn--primary btn--sm">Ativar produto</button>
            </form>
        <?php endif ?>
    </section>
<?php endif ?>

<section class="panel">
    <h2 class="panel__title">Enviar imagens</h2>
    <?php if (count($images) >= $maxImages): ?>
        <p class="muted">Limite de <?= e($maxImages) ?> imagens atingido. Exclua alguma para enviar outra.</p>
    <?php else: ?>
        <form method="post" action="<?= e(url($base)) ?>" enctype="multipart/form-data" class="upload-form">
            <?= csrf_field() ?>
            <div class="field">
                <label for="images">Arquivos</label>
                <input id="images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required>
                <small class="field__hint">JPG, PNG ou WebP · até <?= e($maxMb) ?> MB cada · menor lado com pelo menos 500 px ·
                    ideal: 1600 px ou mais, fundo neutro, luz natural. As imagens são otimizadas automaticamente.</small>
            </div>
            <div class="field">
                <label for="alt_text">Descrição das imagens <span class="field__optional">(opcional)</span></label>
                <input id="alt_text" name="alt_text" type="text" maxlength="150" placeholder="<?= e($product['name']) ?>">
                <small class="field__hint">Texto alternativo para acessibilidade e buscadores. Pode ajustar cada imagem depois.</small>
            </div>
            <button type="submit" class="btn btn--primary">Enviar</button>
        </form>
    <?php endif ?>
</section>

<section class="panel">
    <h2 class="panel__title">Imagens do produto</h2>
    <?php if ($images === []): ?>
        <p class="muted">Nenhuma imagem ainda.</p>
    <?php else: ?>
        <ul class="image-grid">
            <?php foreach ($images as $image): ?>
                <?php $position = array_search($image['id'], $movableIds, true); ?>
                <li class="image-card<?= $image['is_cover'] ? ' image-card--cover' : '' ?>">
                    <a href="<?= e(upload_url($image['path'])) ?>" target="_blank" rel="noopener">
                        <img src="<?= e(upload_url($image['path'], 400)) ?>" alt="<?= e($image['alt_text']) ?>" loading="lazy" width="400" height="400">
                    </a>
                    <?php if ($image['is_cover']): ?><span class="badge badge--accent">Capa</span><?php endif ?>

                    <form method="post" action="<?= e(url("{$base}/{$image['id']}/texto")) ?>" class="image-card__alt">
                        <?= csrf_field() ?>
                        <label for="alt-<?= e($image['id']) ?>" class="visually-hidden">Descrição da imagem</label>
                        <input id="alt-<?= e($image['id']) ?>" name="alt_text" type="text" maxlength="150" value="<?= e($image['alt_text']) ?>" required>
                        <button type="submit" class="btn btn--secondary btn--sm">Salvar</button>
                    </form>

                    <div class="image-card__actions">
                        <?php if (!$image['is_cover']): ?>
                            <form method="post" action="<?= e(url("{$base}/{$image['id']}/capa")) ?>" class="inline-form">
                                <?= csrf_field() ?><button type="submit" class="btn btn--secondary btn--sm">Tornar capa</button>
                            </form>
                            <?php if ($position !== false && $position > 0): ?>
                                <form method="post" action="<?= e(url("{$base}/{$image['id']}/mover")) ?>" class="inline-form">
                                    <?= csrf_field() ?><input type="hidden" name="direction" value="up">
                                    <button type="submit" class="btn btn--secondary btn--sm" aria-label="Mover para antes">←</button>
                                </form>
                            <?php endif ?>
                            <?php if ($position !== false && $position < count($movableIds) - 1): ?>
                                <form method="post" action="<?= e(url("{$base}/{$image['id']}/mover")) ?>" class="inline-form">
                                    <?= csrf_field() ?><input type="hidden" name="direction" value="down">
                                    <button type="submit" class="btn btn--secondary btn--sm" aria-label="Mover para depois">→</button>
                                </form>
                            <?php endif ?>
                        <?php endif ?>
                        <form method="post" action="<?= e(url("{$base}/{$image['id']}/excluir")) ?>" class="inline-form" data-confirm="Excluir esta imagem?">
                            <?= csrf_field() ?><button type="submit" class="btn btn--danger btn--sm">Excluir</button>
                        </form>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>
