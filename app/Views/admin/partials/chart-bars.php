<?php
/**
 * Gráfico de colunas (vendas por dia). O desenho é SVG esticado na largura; os rótulos são HTML,
 * para continuarem legíveis no celular. A CSP não permite style="": alturas vão em atributos do SVG.
 * @var list<array{label: string, value: int, title: string}> $series
 * @var callable(int): string $format  rótulo do eixo (ex.: dinheiro abreviado)
 * @var string $caption  descrição acessível do gráfico
 */
$count = max(1, count($series));
$max = max([1, ...array_column($series, 'value')]);
// Três faixas de valor "redondo" (1, 2, 2,5 ou 5 × potência de 10; em centavos, a partir de R$ 10)
$rough = max(1000, $max / 3);
$magnitude = 10 ** (int) floor(log10($rough));
$step = 10 * $magnitude;
foreach ([1, 2, 2.5, 5] as $m) {
    if ($m * $magnitude >= $rough) {
        $step = (int) ceil($m * $magnitude);
        break;
    }
}
$top = $step * 3;
$ticks = [$top, $step * 2, $step, 0];
$height = 100;
$slot = 100 / $count;
$barWidth = $slot * ($count > 45 ? 0.72 : 0.62);
// Até 6 rótulos no eixo x, espalhados de ponta a ponta
$labelStep = max(1, (int) ceil(($count - 1) / 5));
$xLabels = [];
for ($i = 0; $i < $count; $i += $labelStep) {
    $xLabels[] = $series[$i]['label'];
}
if (($count - 1) % $labelStep !== 0) {
    $xLabels[] = $series[$count - 1]['label'];
}
?>
<figure class="chart">
    <figcaption class="visually-hidden"><?= e($caption) ?></figcaption>
    <div class="chart__y" aria-hidden="true">
        <?php foreach ($ticks as $tick): ?><span><?= e($format($tick)) ?></span><?php endforeach ?>
    </div>
    <div class="chart__plot">
        <svg viewBox="0 0 100 <?= $height ?>" preserveAspectRatio="none" role="img" aria-label="<?= e($caption) ?>">
            <?php foreach ([0, 1 / 3, 2 / 3] as $f): ?>
                <line x1="0" x2="100" y1="<?= round($height * $f, 3) ?>" y2="<?= round($height * $f, 3) ?>" class="chart__grid" vector-effect="non-scaling-stroke"/>
            <?php endforeach ?>
            <line x1="0" x2="100" y1="<?= $height ?>" y2="<?= $height ?>" class="chart__axis" vector-effect="non-scaling-stroke"/>
            <?php foreach ($series as $i => $point): ?>
                <?php $h = $point['value'] > 0 ? max(0.8, $point['value'] / $top * $height) : 0; ?>
                <g class="chart__col">
                    <rect x="<?= round($i * $slot, 3) ?>" y="0" width="<?= round($slot, 3) ?>" height="<?= $height ?>" class="chart__hit"><title><?= e($point['title']) ?></title></rect>
                    <?php if ($h > 0): ?>
                    <rect x="<?= round($i * $slot + ($slot - $barWidth) / 2, 3) ?>" y="<?= round($height - $h, 3) ?>" width="<?= round($barWidth, 3) ?>" height="<?= round($h, 3) ?>" class="chart__bar"><title><?= e($point['title']) ?></title></rect>
                    <?php endif ?>
                </g>
            <?php endforeach ?>
        </svg>
    </div>
    <div class="chart__x" aria-hidden="true">
        <?php foreach ($xLabels as $label): ?><span><?= e($label) ?></span><?php endforeach ?>
    </div>
</figure>
