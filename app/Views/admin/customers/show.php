<?php
/**
 * @var array<string, mixed> $customer
 * @var list<array<string, mixed>> $orders
 * @var list<array<string, mixed>> $addresses
 * @var bool $fullCpf
 * @var bool $isOwner
 * @var list<array{id:int, name:string, is_active:int}> $favorites
 */
use GNesting\Helpers\BrazilianDocument;
use GNesting\Helpers\ZipCode;

$whatsapp = whatsapp_url($customer['phone'], "Olá, {$customer['name']}! Aqui é da G-Nesting.");
?>
<div class="page-header">
    <div>
        <a class="back-link" href="<?= e(url('/admin/clientes')) ?>">← Clientes</a>
        <h1 class="page-title"><?= e($customer['name']) ?></h1>
        <?php if ($customer['anonymized_at']): ?><p><span class="badge">Anonimizado em <?= e(format_datetime($customer['anonymized_at'], 'd/m/Y')) ?></span></p><?php endif ?>
        <p class="customer-tags">
            <span class="badge"><?= $customer['user_id'] ? 'Com conta' : 'Comprou sem conta' ?></span>
            <?php if ($customer['whatsapp_opt_in']): ?><span class="badge">Aceita WhatsApp</span><?php endif ?>
            <?php if ($customer['marketing_opt_in']): ?><span class="badge">Aceita novidades por e-mail</span><?php endif ?>
            <span class="muted">cliente desde <?= e(format_datetime($customer['created_at'], 'd/m/Y')) ?></span>
        </p>
    </div>
</div>

<div class="stats">
    <div class="stat"><span class="stat__label">Pedidos</span><span class="stat__value"><?= e($customer['orders_count']) ?></span></div>
    <div class="stat"><span class="stat__label">Total pago</span><span class="stat__value"><?= e(money((int) $customer['spent_cents'])) ?></span></div>
    <div class="stat"><span class="stat__label">Ticket médio</span><span class="stat__value"><?= (int) $customer['orders_count'] > 0 ? e(money(intdiv((int) $customer['spent_cents'], max(1, (int) $customer['orders_count'])))) : '—' ?></span></div>
    <div class="stat"><span class="stat__label">Último pedido</span><span class="stat__value"><?= $customer['last_order_at'] ? e(format_datetime($customer['last_order_at'], 'd/m/Y')) : '—' ?></span></div>
</div>

<div class="order-admin">
    <section class="panel order-admin__main">
        <h2 class="panel__title">Pedidos</h2>
        <?php if ($orders === []): ?>
            <p class="muted">Nenhum pedido.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Pedido</th><th>Data</th><th>Situação</th><th class="table__num">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><a href="<?= e(url('/admin/pedidos/' . $order['id'])) ?>"><?= e($order['number']) ?></a></td>
                            <td class="nowrap"><?= e(format_datetime($order['placed_at'], 'd/m/Y')) ?></td>
                            <td><?= $this->partial('admin/orders/status-badge', ['status' => $order['status']]) ?></td>
                            <td class="table__num nowrap"><?= e(money((int) $order['total_cents'])) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>

    <aside class="order-admin__side">
        <?php if (!$customer['anonymized_at']): ?>
        <section class="panel">
            <h2 class="panel__title">Observações da equipe</h2>
            <form method="post" action="<?= e(url('/admin/clientes/' . $customer['id'] . '/observacoes')) ?>">
                <?= csrf_field() ?>
                <?= $this->partial('partials/textarea', ['name' => 'notes', 'label' => 'Anotações internas', 'value' => $customer['notes'] ?? '', 'rows' => 4,
                    'maxlength' => 5000, 'required' => false, 'errors' => $errors ?? [], 'old' => $old ?? [],
                    'hint' => 'Só a equipe vê. Ex.: "prefere contato pelo WhatsApp", "compra para revenda".']) ?>
                <button type="submit" class="btn btn--secondary btn--sm">Salvar observações</button>
            </form>
        </section>
        <?php endif ?>
        <section class="panel">
            <h2 class="panel__title">Contato</h2>
            <p><?= e($customer['email']) ?><br>
                <?= e(BrazilianDocument::formatPhone($customer['phone'])) ?: '<span class="muted">sem telefone</span>' ?><br>
                CPF <?= e($fullCpf ? BrazilianDocument::formatCpf($customer['cpf']) : BrazilianDocument::maskCpf($customer['cpf'])) ?: '—' ?></p>
            <?php if ($whatsapp !== null): ?>
                <a class="btn btn--secondary btn--sm" href="<?= e($whatsapp) ?>" target="_blank" rel="noopener noreferrer">Abrir WhatsApp</a>
            <?php endif ?>
        </section>
        <?php if ($favorites !== []): ?>
            <section class="panel">
                <h2 class="panel__title">Favoritos na loja</h2>
                <ul class="plain-list">
                    <?php foreach ($favorites as $fav): ?>
                        <li><?= e($fav['name']) ?><?= $fav['is_active'] ? '' : ' <span class="muted">(inativo)</span>' ?></li>
                    <?php endforeach ?>
                </ul>
            </section>
        <?php endif ?>
        <?php if ($addresses !== []): ?>
            <section class="panel">
                <h2 class="panel__title">Endereços salvos</h2>
                <?php foreach ($addresses as $address): ?>
                    <p><?= e($address['recipient_name']) ?><br>
                        <?= e("{$address['street']}, {$address['number']}" . ($address['complement'] ? " — {$address['complement']}" : '')) ?><br>
                        <?= e("{$address['district']} · {$address['city']}/{$address['state']} · " . ZipCode::format((string) $address['zip_code'])) ?></p>
                <?php endforeach ?>
            </section>
        <?php endif ?>
        <?php if ($isOwner && !$customer['anonymized_at']): ?>
            <section class="panel">
                <h2 class="panel__title">Dados pessoais (LGPD)</h2>
                <p class="muted panel__intro">Use a pedido do próprio cliente, depois de confirmar a identidade dele (ex.: resposta do e-mail cadastrado). As duas ações ficam registradas na auditoria.</p>
                <form method="post" action="<?= e(url('/admin/clientes/' . $customer['id'] . '/exportar')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--secondary btn--sm">Exportar dados (JSON)</button>
                </form>
                <form method="post" action="<?= e(url('/admin/clientes/' . $customer['id'] . '/anonimizar')) ?>" class="danger-zone">
                    <?= csrf_field() ?>
                    <p>Anonimizar apaga conta, endereços, contato e carrinhos. Pedidos dos últimos <?= (int) config('security.privacy.order_retention_years', 5) ?> anos ficam guardados (obrigação fiscal). Não dá para desfazer.</p>
                    <?= $this->partial('partials/field', ['name' => 'confirm', 'label' => 'Digite ANONIMIZAR para confirmar', 'autocomplete' => 'off', 'errors' => [], 'old' => []]) ?>
                    <button type="submit" class="btn btn--danger btn--sm">Anonimizar cadastro</button>
                </form>
            </section>
        <?php endif ?>
    </aside>
</div>
