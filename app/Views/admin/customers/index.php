<?php
/**
 * @var list<array<string, mixed>> $customers
 * @var \GNesting\Core\Paginator $paginator
 * @var string $q
 * @var bool $fullCpf
 */
use GNesting\Helpers\BrazilianDocument;
?>
<div class="page-header">
    <h1 class="page-title">Clientes</h1>
</div>

<section class="panel">
    <form method="get" action="<?= e(url('/admin/clientes')) ?>" class="inline-add">
        <label class="visually-hidden" for="q">Buscar cliente</label>
        <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Nome, e-mail, CPF ou telefone">
        <button type="submit" class="btn btn--secondary btn--sm">Buscar</button>
    </form>

    <?php if ($customers === []): ?>
        <p class="muted">Nenhum cliente encontrado.</p>
    <?php else: ?>
        <p class="table-summary"><?= e($paginator->total) ?> cliente(s)</p>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Cliente</th><th>CPF</th><th>Conta</th><th class="table__num">Pedidos</th><th class="table__num">Total pago</th><th>Último pedido</th></tr></thead>
                <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td><a href="<?= e(url('/admin/clientes/' . $customer['id'])) ?>"><?= e($customer['name']) ?></a><br>
                            <small class="muted"><?= e($customer['email']) ?></small></td>
                        <td class="nowrap"><?= e($fullCpf ? BrazilianDocument::formatCpf($customer['cpf']) : BrazilianDocument::maskCpf($customer['cpf'])) ?: '—' ?></td>
                        <td><?= $customer['user_id'] ? '<span class="status status--on">Com conta</span>' : '<span class="status">Visitante</span>' ?></td>
                        <td class="table__num"><?= e($customer['orders_count']) ?></td>
                        <td class="table__num nowrap"><?= e(money((int) $customer['spent_cents'])) ?></td>
                        <td class="nowrap"><?= $customer['last_order_at'] ? e(format_datetime($customer['last_order_at'], 'd/m/Y')) : '—' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?= $this->partial('partials/pagination', ['paginator' => $paginator, 'path' => '/admin/clientes', 'params' => ['q' => $q]]) ?>
    <?php endif ?>
</section>
