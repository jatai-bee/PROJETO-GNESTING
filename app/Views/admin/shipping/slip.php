<?php
/**
 * Romaneio (impressão): conferência dos itens e endereço para a etiqueta.
 * @var array<string, mixed>       $order
 * @var list<array<string, mixed>> $items
 * @var int $weight gramas
 */
use GNesting\Helpers\BrazilianDocument;
use GNesting\Helpers\ZipCode;
?>
<div class="slip">
    <header class="slip__head">
        <img src="<?= e(asset('img/logo.svg')) ?>" alt="G-Nesting" width="160" height="30">
        <div><strong>Romaneio</strong><br><?= e($order['number']) ?></div>
    </header>

    <section class="slip__address">
        <p class="slip__label">Destinatário</p>
        <p class="slip__recipient"><?= e($order['ship_recipient']) ?></p>
        <p><?= e("{$order['ship_street']}, {$order['ship_number']}" . ($order['ship_complement'] ? " — {$order['ship_complement']}" : '')) ?><br>
            <?= e("{$order['ship_district']} · {$order['ship_city']}/{$order['ship_state']}") ?><br>
            <strong>CEP <?= e(ZipCode::format((string) $order['ship_zip_code'])) ?></strong></p>
        <p>Tel. <?= e(BrazilianDocument::formatPhone($order['customer_phone'])) ?> · CPF <?= e(BrazilianDocument::formatCpf($order['customer_cpf'])) ?></p>
        <p><?= e($order['shipping_carrier'] . ' · ' . $order['shipping_service']) ?> · ≈ <?= e(format_decimal(number_format($weight / 1000, 2, '.', ''))) ?> kg</p>
    </section>

    <table class="slip__items">
        <thead><tr><th>Conferido</th><th>Qtd.</th><th>Item</th><th>SKU</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td class="slip__check">☐</td>
                <td><?= e($item['quantity']) ?></td>
                <td><?= e($item['product_name']) ?><?= $item['variant_name'] ? ' · ' . e($item['variant_name']) : '' ?>
                    <?php foreach ($item['personalization'] as $choice): ?>
                        <br><small><?= e($choice['label']) ?>: <strong><?= e($choice['value_label'] ?? $choice['value_text']) ?></strong></small>
                    <?php endforeach ?></td>
                <td><?= e($item['sku']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>

    <p class="slip__footer">Conferido por: ____________________ Data: ___/___/______</p>
    <p class="no-print"><button type="button" class="btn btn--primary" data-print>Imprimir</button></p>
</div>
