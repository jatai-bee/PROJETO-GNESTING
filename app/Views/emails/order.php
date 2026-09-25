<?php
/**
 * E-mail ao cliente (TEXTO SIMPLES — nada aqui é HTML, por isso sem e()).
 * @var array<string, mixed>      $order
 * @var string                    $intro     parágrafo principal
 * @var list<array<string, mixed>>|null $items  itens (confirmação do pedido)
 * @var string|null               $details   bloco extra (rastreio, mensagem...)
 * @var string                    $link      link privado do pedido
 */
?>
Olá, <?= explode(' ', (string) $order['customer_name'])[0] ?>!

<?= $intro . "\n" ?>

<?php if (!empty($items)): ?>
<?php foreach ($items as $item): ?>
- <?= $item['quantity'] ?> × <?= $item['product_name'] ?><?= $item['variant_name'] ? " ({$item['variant_name']})" : '' ?> — <?= money((int) $item['line_total_cents']) ?>

<?php foreach ($item['personalization'] as $choice): ?>
    <?= $choice['label'] ?>: <?= $choice['value_label'] ?? $choice['value_text'] ?>

<?php endforeach ?>
<?php endforeach ?>

Frete (<?= $order['shipping_service'] ?>): <?= money((int) $order['shipping_cents']) ?>

Total: <?= money((int) $order['total_cents']) ?>


<?php endif ?>
<?php if (!empty($details)): ?>
<?= $details ?>


<?php endif ?>
Acompanhe o pedido <?= $order['number'] ?>:
<?= $link ?>


<?= config('app.name') ?> — <?= config('app.tagline') ?>

Dúvidas? Responda este e-mail.
