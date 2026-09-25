<?php

declare(strict_types=1);

namespace GNesting\Services\Operations;

use GNesting\Core\Config;

/** Último resultado do cron (storage/cache/cron.json), lido por /saude e pela tela Sistema. */
final class CronHeartbeat
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string, array{ok: bool, detail: string}> $tasks */
    public function record(string $startedAtUtc, float $seconds, array $tasks): void
    {
        $file = $this->file();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0770, true);
        }
        file_put_contents($file, (string) json_encode([
            'finished_at_utc' => gmdate('Y-m-d H:i:s'),
            'started_at_utc' => $startedAtUtc,
            'seconds' => round($seconds, 2),
            'tasks' => $tasks,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** @return array{finished_at_utc: string, started_at_utc: string, seconds: float, tasks: array<string, array{ok: bool, detail: string}>}|null */
    public function last(): ?array
    {
        $data = is_file($this->file()) ? json_decode((string) file_get_contents($this->file()), true) : null;

        return is_array($data) ? $data : null;
    }

    /** Minutos desde o último cron concluído (null = nunca rodou). */
    public function ageMinutes(): ?int
    {
        $last = $this->last();

        return $last === null ? null : (int) floor((time() - (int) strtotime($last['finished_at_utc'] . ' UTC')) / 60);
    }

    private function file(): string
    {
        return $this->config->get('paths.storage') . '/cache/cron.json';
    }
}
