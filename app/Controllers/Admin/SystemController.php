<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Maintenance;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Services\AuditService;
use GNesting\Services\Operations\BackupService;
use GNesting\Services\Operations\CronHeartbeat;
use GNesting\Services\Operations\HealthCheck;
use GNesting\Services\Operations\ProductionCheck;
use Throwable;

/** Sistema (somente proprietário): saúde, lista de verificação, backups, cron e manutenção. */
final class SystemController extends Controller
{
    private const FILES = ['banco' => 'database.sql.gz', 'arquivos' => 'files.tar.gz'];

    public function __construct(
        private readonly HealthCheck $health,
        private readonly ProductionCheck $checks,
        private readonly BackupService $backups,
        private readonly CronHeartbeat $heartbeat,
        private readonly Maintenance $maintenance,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render('admin/system/index', [
            'title' => 'Sistema | Painel',
            'health' => $this->health->run(),
            'checks' => $this->checks->run(),
            'backups' => $this->backups->list(),
            'cron' => $this->heartbeat->last(),
            'maintenance' => $this->maintenance->status(),
            'release' => $this->release(),
        ], 'admin');
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
