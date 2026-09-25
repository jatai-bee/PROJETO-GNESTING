<?php

declare(strict_types=1);

namespace GNesting\Services\Operations;

use GNesting\Core\Config;

/**
 * Limpeza de arquivos que crescem sem parar numa hospedagem compartilhada:
 * logs diários antigos e sessões expiradas (muitas hospedagens desligam o coletor
 * de sessões do PHP quando o caminho é personalizado, como o nosso storage/sessions).
 */
final class Housekeeping
{
    public function __construct(private readonly Config $config)
    {
    }

    public function purgeOldLogs(): int
    {
        $days = max(1, (int) $this->config->get('operations.log_retention_days', 30));

        return $this->deleteOlderThan((string) $this->config->get('paths.logs'), '*.log', $days * 86400, keepActive: ['php-errors.log']);
    }

    public function purgeExpiredSessions(): int
    {
        $lifetime = max(1, (int) $this->config->get('security.session.lifetime_minutes', 120)) * 60;
        // O painel pode durar até o limite absoluto; nenhuma sessão vale mais que 12 h + folga
        $maxAge = max($lifetime, (int) $this->config->get('security.session.admin_absolute_hours', 12) * 3600) + 3600;

        return $this->deleteOlderThan($this->config->get('paths.storage') . '/sessions', 'sess_*', $maxAge);
    }

    /** php-errors.log não tem data no nome: se passar de 10 MB, é renomeado com a data (e some no prazo dos logs). */
    public function rotatePhpErrorLog(): bool
    {
        $file = $this->config->get('paths.logs') . '/php-errors.log';
        if (!is_file($file) || filesize($file) < 10 * 1024 * 1024) {
            return false;
        }

        return rename($file, $this->config->get('paths.logs') . '/php-errors-' . gmdate('Y-m-d-His') . '.log');
    }

    /** @param list<string> $keepActive */
    private function deleteOlderThan(string $dir, string $pattern, int $seconds, array $keepActive = []): int
    {
        $removed = 0;
        foreach (glob($dir . '/' . $pattern) ?: [] as $file) {
            if (is_file($file) && !in_array(basename($file), $keepActive, true) && filemtime($file) < time() - $seconds && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }
}
