<?php
/**
 * @var array<string, mixed>       $product
 * @var array<string, mixed>       $variant
 * @var list<array<string, mixed>> $variants
 * @var array<string, mixed>|null  $spec
 * @var list<array<string, string>> $rows     etapas (existentes + linhas em branco)
 * @var list<array<string, mixed>> $files
 * @var array{total: int, operator: int, passive: int} $minutes
 * @var list<string> $missing
 * @var int|null $materialCost
 * @var list<array<string, mixed>> $materials
 * @var array<string, string> $stages
 * @var array<string, string> $fileTypes
 * @var list<string> $extensions
 * @var int $maxMb
 * @var array $errors
 * @var array $old
 */
$f = fn (string $partial, array $vars): string => $this->partial($partial, $vars + ['errors' => $errors, 'old' => $old]);
$s = $spec ?? [];
$base = "/admin/produtos/{$product['id']}/ficha-producao/{$variant['id']}";
$materialOptions = [];
foreach ($materials as $m) {
    if ($m['is_active'] || (int) $m['id'] === (int) ($s['material_id'] ?? 0)) {
        $materialOptions[$m['id']] = "{$m['code']} — {$m['name']} " . format_decimal($m['thickness_mm']) . ' mm' . ($m['is_active'] ? '' : ' (inativo)');
    }
}
$others = array_values(array_filter($variants, static fn (array $v): bool => (int) $v['id'] !== (int) $variant['id']));
$stepError = static fn (int $n, string $field): ?string => $errors["step_{$n}_{$field}"] ?? null;
$kb = static fn (int $bytes): string => $bytes >= 1048576 ? format_decimal(number_format($bytes / 1048576, 1, '.', '')) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url('/admin/fichas')) ?>">← Fichas de produção</a>
        <h1 class="page-title"><?= e($product['name']) ?></h1>
    </div>
    <?= $this->partial('admin/products/tabs', ['product' => $product, 'active' => 'ficha']) ?>
</div>

<?php if (count($variants) > 1): ?>
    <nav class="variant-switch" aria-label="Variações">
        <?php foreach ($variants as $v): ?>
            <a href="<?= e(url("/admin/produtos/{$product['id']}/ficha-producao/{$v['id']}")) ?>"<?= (int) $v['id'] === (int) $variant['id'] ? ' aria-current="page"' : '' ?>>
                <?= e($v['name'] ?? $v['sku']) ?>
            </a>
        <?php endforeach ?>
    </nav>
<?php endif ?>

<section class="status-bar status-bar--<?= $missing === [] ? 'on' : 'off' ?>">
    <div>
        <strong><?= e($variant['name'] ?? 'Variação única') ?> · <code><?= e($variant['sku']) ?></code></strong>
        <?php if ($missing === []): ?>
            <span>Ficha completa.</span>
        <?php else: ?>
            <span>Falta: <?= e(implode(', ', $missing)) ?>.</span>
        <?php endif ?>
    </div>
    <?php if ($spec !== null && !empty($spec['updated_by_name'])): ?>
        <small>Atualizada por <?= e($spec['updated_by_name']) ?> em <?= e(format_datetime($spec['updated_at'])) ?></small>
    <?php endif ?>
</section>

<div class="stats">
    <div class="stat"><span class="stat__label">Tempo total por peça</span><span class="stat__value"><?= e(format_minutes($minutes['total'])) ?></span></div>
    <div class="stat"><span class="stat__label">Tempo de operador</span><span class="stat__value"><?= e(format_minutes($minutes['operator'])) ?></span>
        <span class="stat__meta">sem etapas passivas</span></div>
    <div class="stat"><span class="stat__label">Etapas passivas</span><span class="stat__value"><?= e(format_minutes($minutes['passive'])) ?></span>
        <span class="stat__meta">secagem e esperas</span></div>
    <div class="stat"><span class="stat__label">Material por peça</span><span class="stat__value"><?= $materialCost !== null ? e(money($materialCost)) : '—' ?></span>
        <span class="stat__meta">custo da chapa ÷ peças por chapa</span></div>
</div>

<form method="post" action="<?= e(url($base)) ?>" class="form-layout" novalidate>
    <?= csrf_field() ?>

    <section class="panel">
        <h2 class="panel__title">Material e corte</h2>
        <div class="form-grid">
            <?= $f('partials/select', ['name' => 'material_id', 'label' => 'Material', 'value' => $s['material_id'] ?? '', 'options' => $materialOptions,
                'placeholder' => 'Sem material', 'required' => false]) ?>
            <?= $f('partials/field', ['name' => 'thickness_mm', 'label' => 'Espessura', 'value' => format_decimal($s['thickness_mm'] ?? null), 'required' => false,
                'inputmode' => 'decimal', 'suffix' => 'mm', 'hint' => 'Vazio = espessura do material.']) ?>
            <?= $f('partials/field', ['name' => 'cut_width_mm', 'label' => 'Largura de corte', 'value' => $s['cut_width_mm'] ?? '', 'required' => false, 'inputmode' => 'numeric', 'suffix' => 'mm']) ?>
            <?= $f('partials/field', ['name' => 'cut_height_mm', 'label' => 'Altura de corte', 'value' => $s['cut_height_mm'] ?? '', 'required' => false, 'inputmode' => 'numeric', 'suffix' => 'mm']) ?>
            <?= $f('partials/field', ['name' => 'pieces_per_sheet', 'label' => 'Peças por chapa', 'value' => $s['pieces_per_sheet'] ?? '', 'required' => false, 'inputmode' => 'numeric']) ?>
            <?= $f('partials/field', ['name' => 'sheet_yield_percent', 'label' => 'Aproveitamento da chapa', 'value' => format_decimal($s['sheet_yield_percent'] ?? null), 'required' => false,
                'inputmode' => 'decimal', 'suffix' => '%']) ?>
            <?= $f('partials/field', ['name' => 'cnc_program_ref', 'label' => 'Programa CNC', 'value' => $s['cnc_program_ref'] ?? '', 'required' => false, 'maxlength' => 100,
                'placeholder' => 'CNC-REL-GEO-001-v1', 'hint' => 'Código do programa na máquina ou nome do arquivo.']) ?>
        </div>
    </section>

    <section class="panel">
        <h2 class="panel__title">Etapas e tempos <small class="muted">(por peça)</small></h2>
        <p class="muted">Deixe uma linha em branco para ignorá-la. "Passiva" = não ocupa operador (ex.: secagem). Para reordenar, mude a posição.</p>
        <?php if (!empty($errors['steps'])): ?><p class="field__error"><?= e($errors['steps']) ?></p><?php endif ?>
        <div class="table-wrap">
            <table class="table steps-table">
                <thead>
                <tr><th>Pos.</th><th>Etapa</th><th>Descrição</th><th>Ferramenta</th><th class="table__num">Oper.</th><th class="table__num">Minutos</th><th>Passiva</th><th>Remover</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $n => $row): ?>
                    <?php $name = static fn (string $field): string => "steps[{$n}][{$field}]"; ?>
                    <tr<?= $row['stage'] === '' ? ' class="steps-table__blank"' : '' ?>>
                        <td><input class="input--xs" name="<?= e($name('position')) ?>" value="<?= e($row['position']) ?>" inputmode="numeric" aria-label="Posição da etapa <?= e($n + 1) ?>"></td>
                        <td>
                            <select name="<?= e($name('stage')) ?>" aria-label="Etapa <?= e($n + 1) ?>"<?= $stepError($n, 'stage') ? ' aria-invalid="true"' : '' ?>>
                                <option value="">—</option>
                                <?php foreach ($stages as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $row['stage'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach ?>
                            </select>
                            <?php if ($stepError($n, 'stage')): ?><p class="field__error"><?= e($stepError($n, 'stage')) ?></p><?php endif ?>
                        </td>
                        <td>
                            <input name="<?= e($name('description')) ?>" value="<?= e($row['description']) ?>" maxlength="200" aria-label="Descrição da etapa <?= e($n + 1) ?>">
                            <?php if ($stepError($n, 'description')): ?><p class="field__error"><?= e($stepError($n, 'description')) ?></p><?php endif ?>
                        </td>
                        <td><input name="<?= e($name('tool')) ?>" value="<?= e($row['tool']) ?>" maxlength="100" aria-label="Ferramenta da etapa <?= e($n + 1) ?>"></td>
                        <td>
                            <input class="input--xs" name="<?= e($name('operations')) ?>" value="<?= e($row['operations']) ?>" inputmode="numeric" aria-label="Operações da etapa <?= e($n + 1) ?>">
                            <?php if ($stepError($n, 'operations')): ?><p class="field__error"><?= e($stepError($n, 'operations')) ?></p><?php endif ?>
                        </td>
                        <td>
                            <input class="input--xs" name="<?= e($name('minutes')) ?>" value="<?= e($row['minutes']) ?>" inputmode="numeric" aria-label="Minutos da etapa <?= e($n + 1) ?>"<?= $stepError($n, 'minutes') ? ' aria-invalid="true"' : '' ?>>
                            <?php if ($stepError($n, 'minutes')): ?><p class="field__error"><?= e($stepError($n, 'minutes')) ?></p><?php endif ?>
                        </td>
                        <td class="steps-table__check"><input type="checkbox" name="<?= e($name('passive')) ?>" value="1"<?= $row['passive'] !== '' ? ' checked' : '' ?> aria-label="Etapa <?= e($n + 1) ?> passiva"></td>
                        <td class="steps-table__check">
                            <?php if ($row['stage'] !== ''): ?><input type="checkbox" name="<?= e($name('remove')) ?>" value="1" aria-label="Remover etapa <?= e($n + 1) ?>"><?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2 class="panel__title">Acabamento e observações</h2>
        <?= $f('partials/textarea', ['name' => 'finish_notes', 'label' => 'Instruções de acabamento', 'value' => $s['finish_notes'] ?? '', 'rows' => 3, 'required' => false, 'maxlength' => 5000]) ?>
        <?= $f('partials/textarea', ['name' => 'internal_notes', 'label' => 'Observações internas', 'value' => $s['internal_notes'] ?? '', 'rows' => 3, 'required' => false, 'maxlength' => 5000,
            'hint' => 'Componentes, montagem, cuidados. Visível só para a equipe.']) ?>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary"><?= $spec ? 'Salvar ficha' : 'Criar ficha' ?></button>
    </div>
</form>

<?php if ($others !== []): ?>
    <section class="panel">
        <h2 class="panel__title">Copiar de outra variação</h2>
        <form method="post" action="<?= e(url("{$base}/copiar")) ?>" class="inline-add"
              data-confirm="Substituir material, medidas, observações e etapas desta variação pelos da variação escolhida?">
            <?= csrf_field() ?>
            <label class="visually-hidden" for="from_variant_id">Variação de origem</label>
            <select id="from_variant_id" name="from_variant_id">
                <?php foreach ($others as $other): ?>
                    <option value="<?= e($other['id']) ?>"><?= e(($other['name'] ?? 'Única') . ' — ' . $other['sku']) ?></option>
                <?php endforeach ?>
            </select>
            <button type="submit" class="btn btn--secondary btn--sm">Copiar ficha</button>
        </form>
        <p class="field__hint">Arquivos não são copiados: cada variação tem os seus.</p>
    </section>
<?php endif ?>

<section class="panel">
    <h2 class="panel__title">Arquivos de produção</h2>
    <?php if ($spec === null): ?>
        <p class="muted">Salve a ficha para poder enviar arquivos.</p>
    <?php else: ?>
        <form method="post" action="<?= e(url("{$base}/arquivos")) ?>" enctype="multipart/form-data" class="upload-form">
            <?= csrf_field() ?>
            <div class="form-grid form-grid--2">
                <div class="field">
                    <label for="file_type">Tipo</label>
                    <select id="file_type" name="file_type">
                        <?php foreach ($fileTypes as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="field">
                    <label for="file">Arquivo</label>
                    <input id="file" name="file" type="file" accept="<?= e('.' . implode(',.', $extensions)) ?>" required>
                    <small class="field__hint"><?= e(implode(', ', $extensions)) ?> · até <?= e($maxMb) ?> MB. Mesmo nome = nova versão (as anteriores ficam guardadas).</small>
                </div>
            </div>
            <button type="submit" class="btn btn--primary btn--sm">Enviar arquivo</button>
        </form>

        <?php if ($files !== []): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>Arquivo</th><th>Tipo</th><th>Versão</th><th class="table__num">Tamanho</th><th>SHA-256</th><th>Enviado</th><th><span class="visually-hidden">Ações</span></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($files as $file): ?>
                        <tr>
                            <td><?= e($file['original_name']) ?></td>
                            <td><?= e($fileTypes[$file['file_type']] ?? $file['file_type']) ?></td>
                            <td>v<?= e($file['version']) ?></td>
                            <td class="table__num nowrap"><?= e($kb((int) $file['size_bytes'])) ?></td>
                            <td><code title="<?= e($file['checksum_sha256']) ?>"><?= e(substr($file['checksum_sha256'], 0, 12)) ?>…</code></td>
                            <td class="nowrap"><?= e(format_datetime($file['created_at'])) ?><?php if ($file['uploaded_by_name']): ?><br><small class="muted"><?= e($file['uploaded_by_name']) ?></small><?php endif ?></td>
                            <td class="table__actions">
                                <a class="btn btn--secondary btn--sm" href="<?= e(url("/admin/arquivos-producao/{$file['id']}")) ?>">Baixar</a>
                                <form method="post" action="<?= e(url("{$base}/arquivos/{$file['id']}/excluir")) ?>" class="inline-form"
                                      data-confirm="Excluir <?= e($file['original_name']) ?> v<?= e($file['version']) ?>?">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn--danger btn--sm">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    <?php endif ?>
</section>
