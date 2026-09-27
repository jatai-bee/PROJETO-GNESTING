<?php

declare(strict_types=1);

namespace GNesting\Services\Operations;

use GNesting\Core\Config;

/**
 * Tarefas periódicas disparadas por URL (public/cron.php?token=CRON_TOKEN), para hospedagem sem
 * Cron Jobs: um serviço de ping gratuito (cron-job.org e afins) chama o endereço a cada 15 min.
 * Roda exatamente o mesmo CronRunner do bin/cron.php: não há segunda cópia da lógica.
 *
 * Sem CRON_TOKEN a porta fica fechada, e a resposta é igual à de token errado (404): quem
 * sonda a URL não descobre se o recurso existe.
 */
final class WebCron
{
    public function __construct(
        private readonly Config $config,
        private readonly CronRunner $runner,
    ) {
    }

    /** @return array{0: int, 1: string} status HTTP e corpo (texto) */
    public function handle(string $offeredToken): array
    {
        $token = (string) $this->config->get('operations.cron_token', '');
        if ($token === '' || $offeredToken === '' || strlen($offeredToken) > 200 || !hash_equals($token, $offeredToken)) {
            return [404, "não encontrado\n"];
        }

        $failed = array_keys(array_filter($this->runner->run(), fn (array $r) => !$r['ok']));

        return $failed === [] ? [200, "ok\n"] : [500, 'falhou: ' . implode(', ', $failed) . "\n"];
    }
}
