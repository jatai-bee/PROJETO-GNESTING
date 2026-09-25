<?php

declare(strict_types=1);

namespace GNesting\Services\Operations;

use GNesting\Core\Config;
use GNesting\Core\Database;
use GNesting\Core\Maintenance;
use GNesting\Core\Migrations\Migrator;
use Throwable;

/**
 * Saúde do sistema, para o monitor externo (/saude) e a tela Sistema do painel.
 *
 * - falha    : algo crítico parou (banco, gravação em disco) → /saude responde 503
 * - atencao  : precisa de cuidado, mas a loja funciona (cron parado, backup velho, disco cheio, migration pendente)
 * - ok
 */
final class HealthCheck
{
    public const OK = 'ok';
    public const WARNING = 'atencao';
    public const FAILURE = 'falha';

    private const MIN_FREE_BYTES = 500 * 1024 * 1024;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly CronHeartbeat $heartbeat,
        private readonly BackupService $backups,
        private readonly Maintenance $maintenance,
    ) {
    }

    /** @return array{status: string, checks: array<string, array{ok: bool, critical: bool, detail: string}>} */
    public function run(): array
    {
        $checks = [
            'banco' => $this->database(),
            'gravacao' => $this->writableDirectories(),
            'migrations' => $this->migrations(),
            'cron' => $this->cron(),
            'backup' => $this->backup(),
            'disco' => $this->disk(),
            'manutencao' => $this->maintenanceMode(),
        ];

        $status = self::OK;
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $status = $check['critical'] ? self::FAILURE : ($status === self::FAILURE ? $status : self::WARNING);
            }
        }

        return ['status' => $status, 'checks' => $checks];
    }

    /** @return array{ok: bool, critical: bool, detail: string} */
    private function database(): array
    {
        try {
            $this->db->pdo()->query('SELECT 1')->fetchColumn();

            return $this->result(true, true, 'conectado');
        } catch (Throwable) {
            return $this->result(false, true, 'sem conexão com o MySQL');
        }
    }

    /** @return array{ok: bool, critical: bool, detail: string} */
    private function writableDirectories(): array
    {
        $storage = (string) $this->config->get('paths.storage');
        $dirs = [
            'logs' => (string) $this->config->get('paths.logs'),
            'sessões' => $storage . '/sessions',
            'cache' => $storage . '/cache',
            'uploads' => (string) $this->config->get('paths.uploads'),
            'arquivos de produção' => $storage . '/private/production_files',
        ];
        $failing = array_keys(array_filter($dirs, fn (string $dir) => !is_dir($dir) || !is_writable($dir)));

        return $failing === []
            ? $this->result(true, true, 'pastas graváveis')
            : $this->result(false, true, 'sem permissão de gravação: ' . implode(', ', $failing));
    }

    /** @return array{ok: bool, critical: bool, detail: string} */
    private function migrations(): array
    {
        try {
            $base = (string) $this->config->get('paths.base') . '/database';
            $status = (new Migrator($this->db->pdo(), $base . '/migrations', $base . '/seeds'))->status();
            $pending = array_keys(array_filter($status, fn (bool $applied, string $name) => !$applied && !str_starts_with($name, 'seed:'), ARRAY_FILTER_USE_BOTH));

            return $pending === []
                ? $this->result(true, false, 'todas aplicadas')
                : $this->result(false, false, 'pendentes: ' . implode(', ', $pending) . ' (rode composer migrate)');
        } catch (Throwable) {
            return $this->result(false, false, 'não foi possível conferir');
        }
    }

    /** @return array{ok: bool, critical: bool, detail: string} */
    private function cron(): array
    {
        $age = $this->heartbeat->ageMinutes();
        if ($age === null) {
            return $this->result(false, false, 'nunca rodou (configure o Cron Job no cPanel)');
        }
        $failed = array_keys(array_filter($this->heartbeat->last()['tasks'] ?? [], fn (array $t) => !$t['ok']));
        if ($age > (int) $this->config->get('operations.cron_stale_minutes', 45)) {
            return $this->result(false, false, "último há {$age} min (esperado: a cada 15 min)");
        }

        return $failed === []
            ? $this->result(true, false, "último há {$age} min")
            : $this->result(false, false, "último há {$age} min; falharam: " . implode(', ', $failed));
    }

    /** @return array{ok: bool, critical: bool, detail: string} */
    private function backup(): array
    {
        $latest = $this->backups->latest();
        if ($latest === null) {
            return $this->result(false, false, 'nenhum backup ainda');
        }
        $hours = (int) floor((time() - (int) strtotime($latest['created_at_utc'] . ' UTC')) / 3600);
        $detail = "último há {$hours} h ({$latest['name']}, " . count($this->backups->list()) . ' guardados)';

        return $this->result($hours <= (int) $this->config->get('operations.backup.stale_hours', 30), false, $detail);
    }

    /** @return array{ok: bool, critical: bool, detail: string} */
    private function disk(): array
    {
        $free = @disk_free_space((string) $this->config->get('paths.storage'));
        if ($free === false) {
            return $this->result(true, false, 'espaço livre indisponível nesta hospedagem');
        }
        $detail = round($free / 1073741824, 1) . ' GB livres';

        return $this->result($free >= self::MIN_FREE_BYTES, false, $detail);
    }

    /** @return array{ok: bool, critical: bool, detail: string} */
    private function maintenanceMode(): array
    {
        $status = $this->maintenance->status();

        return $status === null
            ? $this->result(true, false, 'desligado')
            : $this->result(false, false, 'LIGADO desde ' . $status['since'] . ' UTC');
    }

    /** @return array{ok: bool, critical: bool, detail: string} */
    private function result(bool $ok, bool $critical, string $detail): array
    {
        return ['ok' => $ok, 'critical' => $critical, 'detail' => $detail];
    }
}
