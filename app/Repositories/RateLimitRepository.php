<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

final class RateLimitRepository extends Repository
{
    /** Segundos restantes de bloqueio (0 = liberado). */
    public function blockedSeconds(string $key): int
    {
        $seconds = $this->fetchValue(
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), blocked_until)
               FROM rate_limits
              WHERE bucket_key = :key AND blocked_until > UTC_TIMESTAMP()',
            ['key' => $key]
        );

        return $seconds === null ? 0 : max(1, (int) $seconds);
    }

    /**
     * Registra uma tentativa de forma atômica. Se a janela expirou, recomeça a contagem;
     * ao atingir o máximo, bloqueia pelo tamanho da janela.
     */
    public function hit(string $key, int $maxAttempts, int $windowSeconds): void
    {
        $this->execute(
            'INSERT INTO rate_limits (bucket_key, hits, window_start, blocked_until)
             VALUES (:key, 1, UTC_TIMESTAMP(), IF(:max_insert <= 1, UTC_TIMESTAMP() + INTERVAL :window_insert SECOND, NULL))
             ON DUPLICATE KEY UPDATE
                hits = IF(window_start < UTC_TIMESTAMP() - INTERVAL :window_reset SECOND, 1, hits + 1),
                window_start = IF(hits = 1, UTC_TIMESTAMP(), window_start),
                blocked_until = IF(hits >= :max_update, UTC_TIMESTAMP() + INTERVAL :window_block SECOND, blocked_until)',
            [
                'key' => $key,
                'max_insert' => $maxAttempts,
                'window_insert' => $windowSeconds,
                'window_reset' => $windowSeconds,
                'max_update' => $maxAttempts,
                'window_block' => $windowSeconds,
            ]
        );
    }

    public function clear(string $key): void
    {
        $this->execute('DELETE FROM rate_limits WHERE bucket_key = :key', ['key' => $key]);
    }

    /** Limpeza periódica (cron). */
    public function purgeExpired(int $olderThanSeconds = 86400): int
    {
        return $this->execute(
            'DELETE FROM rate_limits
              WHERE window_start < UTC_TIMESTAMP() - INTERVAL :age SECOND
                AND (blocked_until IS NULL OR blocked_until < UTC_TIMESTAMP())',
            ['age' => $olderThanSeconds]
        );
    }
}
