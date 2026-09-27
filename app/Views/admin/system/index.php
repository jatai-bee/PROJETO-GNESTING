<?php
/**
 * @var array{status: string, checks: array<string, array{ok: bool, critical: bool, detail: string}>} $health
 * @var list<array{label: string, ok: bool, level: string, detail: string}> $checks
 * @var list<array<string, mixed>> $backups
 * @var array<string, mixed>|null $cron
 * @var array<string, string>|null $maintenance
 * @var array<string, string>|null $release
 * @var list<string> $pending migrations não aplicadas
 * @var string|null $cronUrl
 */
use GNesting\Controllers\Admin\SystemController;
use GNesting\Services\Operations\HealthCheck;

$healthLabels = ['banco' => 'Banco de dados', 'gravacao' => 'Gravação em disco', 'migrations' => 'Migrations', 'cron' => 'Cron',
    'backup' => 'Backup', 'disco' => 'Espaço em disco', 'manutencao' => 'Modo manutenção'];
$badge = static fn (bool $ok, bool $critical): string => $ok ? '<span class="status status--on">ok</span>'
    : ($critical ? '<span class="status status--fail">falha</span>' : '<span class="status status--warn">atenção</span>');
$statusText = [HealthCheck::OK => 'Tudo certo', HealthCheck::WARNING => 'Precisa de atenção', HealthCheck::FAILURE => 'Falha crítica'];
$problems = array_filter($checks, fn (array $c) => !$c['ok']);
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Sistema</h1>
        <p class="muted">Saúde da loja, backups e manutenção. Guia completo: docs/17-deploy-e-operacao.md.
            · Versão: <?= $release ? e($release['commit'] . ' (pacote de ' . format_datetime($release['built_at_utc'] ?? null, 'd/m/Y H:i') . ')') : 'desenvolvimento' ?> · PHP <?= e(PHP_VERSION) ?></p>
    </div>
</div>

<div class="status-bar status-bar--<?= $health['status'] === HealthCheck::OK ? 'on' : 'off' ?>">
    <strong><?= e($statusText[$health['status']] ?? $health['status']) ?></strong>
    <span class="muted">Monitor externo: <code>/saude</code> (detalhes com <code>?token=HEALTH_TOKEN</code>)</span>
</div>

<?php if ($pending !== []): ?>
    <section class="panel panel--attention">
        <h2 class="panel__title">Atualização do banco de dados pendente</h2>
        <p class="panel__intro">A versão instalada precisa de <?= count($pending) ?> alteração(ões) no banco: <?= e(implode(', ', $pending)) ?>.
            Antes de aplicar, o sistema faz um backup do banco automaticamente. Se a loja estiver aberta, ligue a manutenção antes.</p>
        <form method="post" action="<?= e(url('/admin/sistema/atualizar-banco')) ?>" data-confirm="Aplicar a atualização do banco de dados agora?">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--primary btn--sm">Atualizar banco de dados</button>
        </form>
    </section>
<?php endif ?>

<div class="order-admin">
    <div class="order-admin__main">
        <section class="panel">
            <h2 class="panel__title">Saúde</h2>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                    <?php foreach ($health['checks'] as $name => $check): ?>
                        <tr>
                            <td><?= e($healthLabels[$name] ?? $name) ?></td>
                            <td><?= $badge($check['ok'], $check['critical']) ?></td>
                            <td class="muted"><?= e($check['detail']) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2 class="panel__title">Lista de verificação de produção</h2>
            <p class="muted panel__intro"><?= $problems === [] ? 'Tudo pronto para produção.' : count($problems) . ' item(ns) a resolver. A maioria se resolve no arquivo .env (cPanel → Gerenciador de Arquivos → Editar).' ?></p>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                    <?php foreach ($checks as $check): ?>
                        <tr>
                            <td><?= e($check['label']) ?></td>
                            <td><?= $badge($check['ok'], $check['level'] === 'erro') ?></td>
                            <td class="muted"><?= e($check['detail']) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel__header">
                <h2 class="panel__title">Backups</h2>
                <form method="post" action="<?= e(url('/admin/sistema/backup')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--secondary btn--sm">Fazer backup agora</button>
                </form>
            </div>
            <p class="muted panel__intro">O cron faz um por dia. Baixe uma cópia ao menos uma vez por semana e guarde fora do servidor, em local protegido: os arquivos têm dados pessoais de clientes.</p>
            <?php if ($backups === []): ?>
                <p class="muted">Nenhum backup ainda.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Backup (UTC)</th><th class="table__num">Tamanho</th><th class="table__num">Linhas</th><th>Baixar</th><th>Restaurar</th></tr></thead>
                        <tbody>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td class="nowrap"><?= e($backup['name']) ?></td>
                                <td class="table__num nowrap"><?= e(number_format($backup['size_bytes'] / 1048576, 1, ',', '.')) ?> MB</td>
                                <td class="table__num"><?= e(number_format((int) $backup['rows'], 0, ',', '.')) ?></td>
                                <td class="nowrap">
                                    <a href="<?= e(url('/admin/sistema/backups/' . $backup['name'] . '/banco')) ?>">banco</a>
                                    <?php if (isset($backup['files']['files.tar.gz'])): ?> · <a href="<?= e(url('/admin/sistema/backups/' . $backup['name'] . '/arquivos')) ?>">arquivos</a><?php endif ?>
                                </td>
                                <td>
                                    <details class="restore">
                                        <summary>Restaurar…</summary>
                                        <form method="post" action="<?= e(url('/admin/sistema/backups/' . $backup['name'] . '/restaurar')) ?>" class="restore__form">
                                            <?= csrf_field() ?>
                                            <p class="muted">Substitui <strong>todo o banco atual</strong> por este backup. A loja entra em manutenção e uma cópia do estado atual é guardada antes.</p>
                                            <?php if (isset($backup['files']['files.tar.gz'])): ?>
                                                <label class="checkbox"><input type="checkbox" name="arquivos" value="1"> Restaurar também fotos e arquivos de produção</label>
                                            <?php endif ?>
                                            <label for="conf-<?= e($backup['name']) ?>">Digite <strong><?= SystemController::RESTORE_CONFIRMATION ?></strong> para confirmar</label>
                                            <input id="conf-<?= e($backup['name']) ?>" name="confirmacao" autocomplete="off" required>
                                            <button type="submit" class="btn btn--secondary btn--sm">Restaurar este backup</button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </section>
    </div>

    <aside class="order-admin__side">
        <?php if (!empty($demoInstalled)): ?>
        <section class="panel panel--attention">
            <h2 class="panel__title">Dados de demonstração</h2>
            <p class="muted panel__intro">A loja tem produtos, clientes e pedidos de demonstração. Quando cadastrar os seus, remova tudo aqui:
                só sai o que a demonstração criou (um backup do banco é feito antes). Produto de demonstração que entrou num pedido real fica desativado.</p>
            <form method="post" action="<?= e(url('/admin/sistema/demonstracao/remover')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="conf-demo">Digite <strong><?= SystemController::DEMO_CONFIRMATION ?></strong> para confirmar</label>
                    <input id="conf-demo" name="confirmacao" autocomplete="off" required>
                </div>
                <button type="submit" class="btn btn--danger btn--sm">Remover dados de demonstração</button>
            </form>
        </section>
        <?php endif ?>
        <section class="panel">
            <h2 class="panel__title">Manutenção</h2>
            <?php if ($maintenance === null): ?>
                <p class="muted panel__intro">A loja está no ar. Ligue antes de atualizar o sistema ou restaurar um backup: os clientes veem "Voltamos já" e você continua com acesso neste navegador.</p>
                <form method="post" action="<?= e(url('/admin/sistema/manutencao')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="ligar">
                    <?= $this->partial('partials/field', ['name' => 'mensagem', 'label' => 'Mensagem aos clientes', 'required' => false, 'maxlength' => 200,
                        'placeholder' => 'Voltamos em 15 minutos.', 'errors' => [], 'old' => []]) ?>
                    <button type="submit" class="btn btn--secondary btn--sm">Ligar manutenção</button>
                </form>
            <?php else: ?>
                <p><span class="status status--warn">Ligada</span> desde <?= e(format_datetime($maintenance['since'])) ?></p>
                <form method="post" action="<?= e(url('/admin/sistema/manutencao')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="desligar">
                    <button type="submit" class="btn btn--primary btn--sm">Desligar e voltar ao ar</button>
                </form>
            <?php endif ?>
        </section>

        <section class="panel">
            <h2 class="panel__title">Último cron</h2>
            <?php if ($cron === null): ?>
                <p class="muted">Nunca rodou. Configure no cPanel → Cron Jobs, a cada 15 minutos: <code>php <?= e((string) config('paths.base')) ?>/bin/cron.php</code></p>
            <?php else: ?>
                <p class="muted panel__intro"><?= e(format_datetime($cron['finished_at_utc'])) ?> · <?= e((string) $cron['seconds']) ?> s</p>
                <ul class="system-tasks">
                    <?php foreach ($cron['tasks'] as $task => $result): ?>
                        <li><?= $badge($result['ok'], false) ?> <?= e(str_replace('_', ' ', $task)) ?> <span class="muted">— <?= e($result['detail']) ?></span></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
            <p class="muted panel__intro">Hospedagem sem Cron Jobs: cadastre num serviço de ping (cron-job.org e afins), a cada 15 minutos,
                <?= $cronUrl !== null ? '<code class="break">' . e($cronUrl) . '</code>' : 'o endereço /cron.php?token=… (preencha CRON_TOKEN no .env para ligar)' ?>.</p>
        </section>
    </aside>
</div>
