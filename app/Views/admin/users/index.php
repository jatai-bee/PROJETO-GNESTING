<?php
/** @var list<array<string, mixed>> $admins */
use GNesting\Enums\AdminRole;
?>
<div class="page-header">
    <h1 class="page-title">Usuários do painel</h1>
    <a class="btn btn--primary" href="<?= e(url('/admin/usuarios/novo')) ?>">Novo usuário</a>
</div>

<section class="panel">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Último acesso</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr></thead>
            <tbody>
            <?php foreach ($admins as $admin): ?>
                <tr>
                    <td><?= e($admin['name']) ?></td>
                    <td><?= e($admin['email']) ?></td>
                    <td><?= e(AdminRole::tryFrom($admin['role'])?->label() ?? $admin['role']) ?></td>
                    <td><?= $admin['last_login_at'] ? e(format_datetime($admin['last_login_at'])) : '<span class="muted">nunca</span>' ?></td>
                    <td><?= $admin['is_active'] ? '<span class="status status--on">Ativo</span>' : '<span class="status status--off">Inativo</span>' ?></td>
                    <td class="table__actions"><a class="btn btn--secondary btn--sm" href="<?= e(url("/admin/usuarios/{$admin['admin_id']}/editar")) ?>">Editar</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2 class="panel__title">O que cada papel pode fazer</h2>
    <dl class="role-list">
        <div><dt>Proprietário</dt><dd>Tudo: também configurações, usuários do painel, auditoria e sistema (backups e manutenção).</dd></div>
        <div><dt>Gestor</dt><dd>Catálogo, cupons, relatórios, pedidos (inclusive cancelar), clientes, produção, estoque e expedição.</dd></div>
        <div><dt>Produção</dt><dd>Fichas de produção, matérias-primas, fila de produção, estoque e expedição. Consulta pedidos e registra notas internas.</dd></div>
        <div><dt>Atendimento</dt><dd>Consulta pedidos e clientes (CPF parcial), envia mensagens ao cliente, reenvia o link do pedido e registra notas.</dd></div>
    </dl>
</section>
