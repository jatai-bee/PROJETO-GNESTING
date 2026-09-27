<?php

declare(strict_types=1);

namespace GNesting\Services\Operations;

use DateTimeImmutable;
use GNesting\Core\Config;
use GNesting\Core\Logger;
use GNesting\Repositories\CartRepository;
use GNesting\Repositories\PasswordResetRepository;
use GNesting\Repositories\RateLimitRepository;
use GNesting\Services\OrderService;
use Throwable;

/**
 * Tarefas periódicas (bin/cron.php, a cada 15 min no cPanel). Cada tarefa roda isolada:
 * a falha de uma não impede as outras; falhas geram alerta por e-mail. O resultado
 * fica em storage/cache/cron.json (CronHeartbeat), que /saude confere.
 */
final class CronRunner
{
    public function __construct(
        private readonly RateLimitRepository $rateLimits,
        private readonly CartRepository $carts,
        private readonly PasswordResetRepository $resets,
        private readonly OrderService $orders,
        private readonly Housekeeping $housekeeping,
        private readonly BackupService $backups,
        private readonly CronHeartbeat $heartbeat,
        private readonly AlertNotifier $alerts,
        private readonly Logger $logger,
        private readonly Config $config,
    ) {
    }

    /**
     * Uma execução por vez: o Cron Jobs e o cron por URL (ou um serviço de ping apressado) podem
     * coincidir, e duas execuções simultâneas fariam dois backups ao mesmo tempo.
     *
     * @return array<string, array{ok: bool, detail: string}>
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $lockFile = $this->config->get('paths.storage') . '/cache/cron.lock';
        if (!is_dir(dirname($lockFile))) {
            @mkdir(dirname($lockFile), 0770, true);
        }
        $lock = @fopen($lockFile, 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return ['execucao' => ['ok' => true, 'detail' => 'outra execução do cron ainda está em andamento']];
        }

        try {
            return $this->runTasks($now);
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return array<string, array{ok: bool, detail: string}> */
    private function runTasks(?DateTimeImmutable $now): array
    {
        $startedAt = gmdate('Y-m-d H:i:s');
        $start = microtime(true);

        $tasks = [
            'limites_de_tentativas' => fn () => $this->rateLimits->purgeExpired() . ' removidos',
            'carrinhos_expirados' => fn () => $this->carts->purgeExpired() . ' removidos',
            'tokens_de_senha' => fn () => $this->resets->purgeExpired() . ' removidos',
            // Pedidos sem pagamento após o prazo: cancelados, estoque reservado volta à venda
            'pedidos_nao_pagos' => fn () => $this->orders->expireUnpaid((int) $this->config->get('payment.expiry_hours', 48)) . ' cancelados',
            'logs_antigos' => fn () => $this->housekeeping->purgeOldLogs() . ' removidos'
                . ($this->housekeeping->rotatePhpErrorLog() ? '; php-errors.log rotacionado' : ''),
            'sessoes_expiradas' => fn () => $this->housekeeping->purgeExpiredSessions() . ' removidas',
            'backup' => function () use ($now): string {
                if (!$this->backups->isDue($now)) {
                    return 'não é hora';
                }
                $manifest = $this->backups->create(now: $now);
                $removed = $this->backups->prune();

                return "criado {$manifest['name']} (" . round(array_sum(array_column($manifest['files'], 'bytes')) / 1048576, 1)
                    . ' MB); ' . count($removed) . ' antigo(s) removido(s)';
            },
        ];

        $results = [];
        foreach ($tasks as $name => $task) {
            try {
                $results[$name] = ['ok' => true, 'detail' => (string) $task()];
            } catch (Throwable $e) {
                $results[$name] = ['ok' => false, 'detail' => $e->getMessage()];
                $this->logger->error("cron: tarefa {$name} falhou: " . $e->getMessage(), ['exception' => $e::class]);
                $this->alerts->notify("Falha no cron: {$name}", "A tarefa \"{$name}\" falhou:\n\n" . $e->getMessage(), 'cron:' . $name);
            }
        }

        $this->heartbeat->record($startedAt, microtime(true) - $start, $results);
        $this->logger->info('cron: concluído', array_map(fn (array $r) => ($r['ok'] ? '' : 'FALHOU: ') . $r['detail'], $results));

        return $results;
    }
}
