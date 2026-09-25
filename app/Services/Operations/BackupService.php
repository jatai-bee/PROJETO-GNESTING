<?php

declare(strict_types=1);

namespace GNesting\Services\Operations;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use GNesting\Core\Config;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Backups em storage/backups/AAAA-MM-DD_HHMMSS/ (fora da web; contém dados pessoais):
 *
 *   database.sql.gz   banco completo (DatabaseDumper)
 *   files.tar.gz      public/uploads + storage/private/production_files (opcional)
 *   manifest.json     data, tamanhos, SHA-256, linhas por tabela, migrations
 *
 * O cron cria um por dia (depois de BACKUP_HOUR) e apaga os antigos: ficam os últimos
 * BACKUP_KEEP_DAILY e o primeiro de cada um dos últimos BACKUP_KEEP_MONTHLY meses.
 * Guardar uma cópia FORA do servidor é tarefa humana: docs/17 §6.
 */
final class BackupService
{
    private const NAME_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';

    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly Config $config,
    ) {
    }

    public function directory(): string
    {
        return (string) $this->config->get('paths.backups');
    }

    /**
     * @return array<string, mixed> manifesto do backup criado
     */
    public function create(?bool $includeFiles = null, ?DateTimeImmutable $now = null): array
    {
        $includeFiles ??= (bool) $this->config->get('operations.backup.include_files', true);
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $name = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d_His');
        $final = $this->directory() . '/' . $name;
        $partial = $final . '.parcial';
        if (is_dir($final)) {
            throw new RuntimeException("Já existe um backup {$name}.");
        }
        $this->ensureDirectory($partial);

        try {
            $started = microtime(true);
            $tables = $this->dumper->dump($partial . '/database.sql.gz');
            $files = ['database.sql.gz' => $this->describe($partial . '/database.sql.gz')];
            if ($includeFiles) {
                $this->archiveFiles($partial . '/files.tar');
                $files['files.tar.gz'] = $this->describe($partial . '/files.tar.gz');
            }

            $manifest = [
                'name' => $name,
                'created_at_utc' => $now->format('Y-m-d H:i:s'),
                'duration_seconds' => round(microtime(true) - $started, 1),
                'app_url' => $this->config->get('app.url'),
                'files' => $files,
                'tables' => $tables,
                'rows' => array_sum($tables),
            ];
            file_put_contents($partial . '/manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if (!rename($partial, $final)) {
                throw new RuntimeException('Não foi possível concluir o backup (renomear a pasta).');
            }
        } catch (Throwable $e) {
            $this->removeDirectory($partial);
            throw $e;
        }

        return $manifest;
    }

    /** @return list<array<string, mixed>> backups completos, do mais novo para o mais antigo */
    public function list(): array
    {
        $backups = [];
        foreach (glob($this->directory() . '/*/manifest.json') ?: [] as $manifestFile) {
            $name = basename(dirname($manifestFile));
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                continue;
            }
            $manifest = json_decode((string) file_get_contents($manifestFile), true);
            if (is_array($manifest)) {
                $manifest['size_bytes'] = array_sum(array_column($manifest['files'] ?? [], 'bytes'));
                $backups[] = $manifest;
            }
        }
        usort($backups, fn (array $a, array $b) => strcmp((string) $b['name'], (string) $a['name']));

        return $backups;
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        return $this->list()[0] ?? null;
    }

    /** Já passou da hora do backup de hoje (horário da loja) e ele ainda não foi feito? */
    public function isDue(?DateTimeImmutable $now = null): bool
    {
        $zone = new DateTimeZone((string) $this->config->get('app.timezone', 'America/Sao_Paulo'));
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($zone);
        if ((int) $now->format('G') < (int) $this->config->get('operations.backup.hour', 3)) {
            return false;
        }
        $latest = $this->latest();
        if ($latest === null) {
            return true;
        }
        $last = (new DateTimeImmutable((string) $latest['created_at_utc'], new DateTimeZone('UTC')))->setTimezone($zone);

        return $last->format('Y-m-d') !== $now->format('Y-m-d');
    }

    /**
     * Apaga os backups fora da política de retenção (e sobras .parcial de falhas).
     *
     * @return list<string> nomes apagados
     */
    public function prune(): array
    {
        $keepDaily = max(1, (int) $this->config->get('operations.backup.keep_daily', 7));
        $keepMonthly = max(0, (int) $this->config->get('operations.backup.keep_monthly', 3));

        $names = array_column($this->list(), 'name');
        $keep = array_slice($names, 0, $keepDaily);
        $firstOfMonth = [];
        foreach (array_reverse($names) as $name) { // do mais antigo para o mais novo
            $firstOfMonth[substr((string) $name, 0, 7)] ??= $name;
        }
        $keep = [...$keep, ...array_slice(array_reverse(array_values($firstOfMonth)), 0, $keepMonthly)];

        $removed = [];
        foreach ($names as $name) {
            if (!in_array($name, $keep, true)) {
                $this->removeDirectory($this->directory() . '/' . $name);
                $removed[] = (string) $name;
            }
        }
        foreach (glob($this->directory() . '/*.parcial') ?: [] as $leftover) {
            if (filemtime($leftover) < time() - 86400) {
                $this->removeDirectory($leftover);
            }
        }

        return $removed;
    }

    /**
     * Restaura um backup: confere os SHA-256, recria o banco e (opcional) os arquivos.
     *
     * @param callable(string): void $output
     */
    public function restore(string $name, bool $includeFiles, callable $output): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new RuntimeException('Nome de backup inválido.');
        }
        $dir = $this->directory() . '/' . $name;
        $manifest = json_decode((string) @file_get_contents($dir . '/manifest.json'), true);
        if (!is_array($manifest)) {
            throw new RuntimeException("Backup {$name} não encontrado ou sem manifest.json.");
        }
        foreach ($manifest['files'] as $file => $info) {
            if (!hash_equals((string) $info['sha256'], (string) hash_file('sha256', $dir . '/' . $file))) {
                throw new RuntimeException("{$file} está corrompido (SHA-256 não confere). Nada foi alterado.");
            }
        }
        $output('Arquivos conferidos (SHA-256).');

        $statements = $this->dumper->restore($dir . '/database.sql.gz');
        $output("Banco restaurado ({$statements} comandos).");

        if ($includeFiles && isset($manifest['files']['files.tar.gz'])) {
            $this->restoreFiles($dir . '/files.tar.gz');
            $output('Arquivos (uploads e produção) restaurados.');
        }
    }

    /**
     * Pastas incluídas no backup de arquivos.
     *
     * @return array<string, string> nome no pacote => caminho real
     */
    private function fileSources(): array
    {
        $storage = (string) $this->config->get('paths.storage');

        return [
            'uploads' => (string) $this->config->get('paths.uploads'),
            'production_files' => $storage . '/private/production_files',
        ];
    }

    private function archiveFiles(string $tarPath): void
    {
        $tar = new PharData($tarPath);
        foreach ($this->fileSources() as $prefix => $source) {
            $tar->addEmptyDir($prefix);
            if (!is_dir($source)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = str_replace('\\', '/', substr((string) $file, strlen($source) + 1));
                $tar->addFile((string) $file, $prefix . '/' . $relative);
            }
        }
        $tar->compress(\Phar::GZ);
        unset($tar);
        \Phar::unlinkArchive($tarPath);
    }

    private function restoreFiles(string $tarGz): void
    {
        $temp = $this->directory() . '/.restaurando-' . bin2hex(random_bytes(4));
        $this->ensureDirectory($temp);
        try {
            (new PharData($tarGz))->extractTo($temp, null, true);
            foreach ($this->fileSources() as $prefix => $target) {
                $this->ensureDirectory($target);
                // Espelha o backup: remove o que não existia nele (mantém .htaccess/.gitkeep)
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                    if (in_array($item->getFilename(), ['.htaccess', '.gitkeep'], true)) {
                        continue;
                    }
                    $item->isDir() ? @rmdir((string) $item) : unlink((string) $item);
                }
                if (is_dir($temp . '/' . $prefix)) {
                    $this->copyDirectory($temp . '/' . $prefix, $target);
                }
            }
        } finally {
            $this->removeDirectory($temp);
        }
    }

    /** @return array{bytes: int, sha256: string} */
    private function describe(string $path): array
    {
        return ['bytes' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path)];
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException("Não foi possível criar {$path}.");
        }
    }

    private function copyDirectory(string $from, string $to): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to . '/' . substr((string) $item, strlen($from) + 1);
            $item->isDir() ? $this->ensureDirectory($target) : copy((string) $item, $target);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($path);
    }
}
