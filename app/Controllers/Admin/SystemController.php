<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Maintenance;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Services\AuditService;
use GNesting\Services\Demo\DemoDataService;
use GNesting\Services\Operations\BackupService;
use GNesting\Services\Operations\CronHeartbeat;
use GNesting\Services\Operations\HealthCheck;
use GNesting\Services\Operations\ProductionCheck;
use GNesting\Services\Operations\SchemaUpdater;
use Throwable;

/**
 * Sistema (somente proprietário): saúde, lista de verificação, backups (fazer, baixar, restaurar),
 * atualização do banco, cron e manutenção. Tudo o que antes exigia Terminal (docs/17).
 */
final class SystemController extends Controller
{
    private const FILES = ['banco' => 'database.sql.gz', 'arquivos' => 'files.tar.gz'];
    public const RESTORE_CONFIRMATION = 'RESTAURAR';
    public const DEMO_CONFIRMATION = 'REMOVER';

    public function __construct(
        private readonly HealthCheck $health,
        private readonly ProductionCheck $checks,
        private readonly BackupService $backups,
        private readonly CronHeartbeat $heartbeat,
        private readonly Maintenance $maintenance,
        private readonly AuditService $audit,
        private readonly SchemaUpdater $schema,
        private readonly DemoDataService $demo,
    ) {
    }

    public function index(Request $request): Response
    {
        try {
            $pending = $this->schema->pending();
        } catch (Throwable) {
            $pending = []; // sem banco: a Saúde já mostra a falha
        }
        try {
            $demoInstalled = $this->demo->isInstalled();
        } catch (Throwable) {
            $demoInstalled = false;
        }

        return $this->render('admin/system/index', [
            'title' => 'Sistema | Painel',
            'health' => $this->health->run(),
            'checks' => $this->checks->run(),
            'backups' => $this->backups->list(),
            'cron' => $this->heartbeat->last(),
            'maintenance' => $this->maintenance->status(),
            'release' => $this->release(),
            'pending' => $pending,
            'cronUrl' => $this->cronUrl(),
            'demoInstalled' => $demoInstalled,
        ], 'admin');
    }

    /**
     * Apaga os dados de demonstração (docs/19): só o que ficou registrado em demo_records.
     * Produto de demonstração que entrou num pedido real é desativado, não apagado. Antes, um backup do banco.
     */
    public function removeDemo(Request $request): Response
    {
        if (mb_strtoupper(trim($request->string('confirmacao'))) !== self::DEMO_CONFIRMATION) {
            $this->flash('error', 'Para remover, digite ' . self::DEMO_CONFIRMATION . ' no campo de confirmação. Nada foi alterado.');

            return $this->redirect('/admin/sistema');
        }
        if (!$this->demo->isInstalled()) {
            $this->flash('success', 'Não há dados de demonstração na loja.');

            return $this->redirect('/admin/sistema');
        }

        @set_time_limit(300);
        ignore_user_abort(true);
        try {
            $safety = $this->backups->create(false)['name'];
            $removed = $this->demo->remove();
            $this->audit->record(AuditService::DELETE, 'demo_data', null, null, $removed + ['backup' => $safety]);
            $parts = [];
            foreach ($removed as $label => $count) {
                if ($count > 0) {
                    $parts[] = "{$count} {$label}";
                }
            }
            $this->flash('success', 'Dados de demonstração removidos' . ($parts !== [] ? ': ' . implode(', ', $parts) : '')
                . ". Backup de antes da remoção: {$safety}.");
        } catch (Throwable $e) {
            $this->flash('error', 'Não foi possível remover os dados de demonstração: ' . $e->getMessage());
        }

        return $this->redirect('/admin/sistema');
    }

    /**
     * Aplica as migrations pendentes de uma versão nova. Antes, um backup do banco: se algo der
     * errado no meio (DDL não tem rollback no MySQL), é para ele que se volta.
     */
    public function migrate(Request $request): Response
    {
        @set_time_limit(300);
        ignore_user_abort(true);
        if ($this->schema->pending() === []) {
            $this->flash('success', 'O banco de dados já está atualizado.');

            return $this->redirect('/admin/sistema');
        }

        $safety = null;
        try {
            $safety = $this->backups->create(false)['name'];
            $applied = $this->schema->apply();
            $this->audit->record(AuditService::UPDATE, 'database_schema', null, null, ['aplicadas' => $applied, 'backup' => $safety]);
            $this->flash('success', 'Banco atualizado: ' . implode(', ', $applied) . ". Backup de antes da atualização: {$safety}.");
        } catch (Throwable $e) {
            $this->flash('error', 'A atualização do banco falhou: ' . $e->getMessage()
                . ($safety !== null ? " Para voltar ao estado anterior, restaure o backup {$safety}." : ''));
        }

        return $this->redirect('/admin/sistema');
    }

    /**
     * Restaura um backup pelo painel. Liga a manutenção (clientes não compram durante a troca),
     * guarda uma cópia do estado atual e só então substitui o banco (e os arquivos, se pedido).
     * A loja fica em manutenção para o proprietário conferir antes de reabrir.
     */
    public function restore(Request $request): Response
    {
        $name = (string) $request->param('nome');
        if (mb_strtoupper(trim($request->string('confirmacao'))) !== self::RESTORE_CONFIRMATION) {
            $this->flash('error', 'Para restaurar, digite ' . self::RESTORE_CONFIRMATION . ' no campo de confirmação. Nada foi alterado.');

            return $this->redirect('/admin/sistema');
        }
        if (!in_array($name, array_column($this->backups->list(), 'name'), true)) {
            throw HttpException::notFound();
        }

        @set_time_limit(600);
        ignore_user_abort(true);
        $secret = $this->maintenance->status() === null
            ? $this->maintenance->enable('Estamos restaurando a loja. Voltamos em alguns minutos.')
            : null;

        $safety = null;
        try {
            $safety = $this->backups->create(false)['name'];
            $this->backups->restore($name, $request->boolean('arquivos'), static function (string $line): void {
            });
            try {
                // O banco agora é o do backup: o usuário desta sessão pode nem existir nele
                $this->audit->record(AuditService::UPDATE, 'backup_restore', null, null, ['nome' => $name, 'copia_anterior' => $safety]);
            } catch (Throwable) {
            }
            $this->flash('success', "Backup {$name} restaurado. A loja está em manutenção: confira e depois clique em \"Desligar e voltar ao ar\". "
                . "Cópia do estado anterior: {$safety}.");
        } catch (Throwable $e) {
            $this->flash('error', 'A restauração falhou: ' . $e->getMessage() . ' A loja continua em manutenção.'
                . ($safety !== null ? " Cópia do estado anterior: {$safety}." : ''));
        }

        $response = $this->redirect('/admin/sistema');

        return $secret === null ? $response : $response->withCookie(Maintenance::BYPASS_COOKIE, hash('sha256', $secret), [
            'httponly' => true, 'samesite' => 'Lax', 'secure' => $request->isSecure(),
        ]);
    }

    /** Endereço do cron por URL (null quando CRON_TOKEN está vazio). */
    private function cronUrl(): ?string
    {
        $token = (string) config('operations.cron_token', '');

        return $token === '' ? null : absolute_url('/cron.php') . '?token=' . $token;
    }

    /**
     * Versão instalada (arquivo RELEASE do pacote gerado por bin/build-release.php).
     *
     * @return array<string, string>|null
     */
    private function release(): ?array
    {
        $file = (string) config('paths.base') . '/RELEASE';
        $data = is_file($file) ? parse_ini_file($file) : false;

        return is_array($data) ? array_map('strval', $data) : null;
    }

    public function backup(Request $request): Response
    {
        @set_time_limit(300);
        try {
            $manifest = $this->backups->create();
            $this->backups->prune();
            $this->audit->record(AuditService::CREATE, 'backup', null, null, ['nome' => $manifest['name'], 'linhas' => $manifest['rows']]);
            $this->flash('success', "Backup {$manifest['name']} criado.");
        } catch (Throwable $e) {
            $this->flash('error', 'O backup falhou: ' . $e->getMessage());
        }

        return $this->redirect('/admin/sistema');
    }

    /** Download para guardar uma cópia fora do servidor. Contém dados pessoais: fica na auditoria. */
    public function download(Request $request): Response
    {
        $name = (string) $request->param('nome');
        $file = self::FILES[(string) $request->param('arquivo')] ?? throw HttpException::notFound();
        $backup = current(array_filter($this->backups->list(), fn (array $b) => $b['name'] === $name)) ?: throw HttpException::notFound();
        $path = $this->backups->directory() . '/' . $backup['name'] . '/' . $file;
        if (!is_file($path)) {
            throw HttpException::notFound();
        }
        $this->audit->record(AuditService::EXPORT, 'backup', null, null, ['nome' => $name, 'arquivo' => $file]);

        return Response::download($path, "gnesting-{$name}-{$file}");
    }

    public function maintenance(Request $request): Response
    {
        if ($request->string('acao') === 'ligar') {
            $secret = $this->maintenance->enable(mb_substr($request->string('mensagem'), 0, 200));
            $this->audit->record(AuditService::UPDATE, 'maintenance', null, null, ['ligada' => true]);
            $this->flash('success', 'Manutenção ligada: a loja mostra "Voltamos já". Você continua com acesso neste navegador.');

            // Passagem para quem ligou (o mesmo cookie que o link ?manutencao=… gravaria)
            return $this->redirect('/admin/sistema')->withCookie(Maintenance::BYPASS_COOKIE, hash('sha256', $secret), [
                'httponly' => true, 'samesite' => 'Lax', 'secure' => $request->isSecure(),
            ]);
        }

        $this->maintenance->disable();
        $this->audit->record(AuditService::UPDATE, 'maintenance', null, null, ['ligada' => false]);
        $this->flash('success', 'Manutenção desligada: a loja voltou ao ar.');

        return $this->redirect('/admin/sistema');
    }
}
