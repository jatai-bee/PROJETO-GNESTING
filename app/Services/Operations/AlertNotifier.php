<?php

declare(strict_types=1);

namespace GNesting\Services\Operations;

use GNesting\Core\Config;
use GNesting\Core\Logger;
use GNesting\Services\Mail\Mailer;
use Throwable;

/**
 * Alerta por e-mail para ALERT_EMAIL (erro 500, falha do cron ou do backup).
 * O mesmo alerta (fingerprint) sai no máximo 1× por hora, e no máximo 20 por dia no total:
 * uma pane não vira uma enxurrada de e-mails. Nunca lança exceção.
 */
final class AlertNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function notify(string $subject, string $body, ?string $fingerprint = null): bool
    {
        $to = (string) $this->config->get('operations.alert_email', '');
        if ($to === '') {
            return false;
        }

        try {
            $fingerprint = hash('sha256', $fingerprint ?? $subject);
            $state = $this->state();
            $today = gmdate('Y-m-d');
            if ($state['day'] !== $today) {
                $state = ['day' => $today, 'count' => 0, 'last' => []];
            }
            $throttle = (int) $this->config->get('operations.alert_throttle_minutes', 60) * 60;
            if ($state['count'] >= (int) $this->config->get('operations.alert_max_per_day', 20)
                || ($state['last'][$fingerprint] ?? 0) > time() - $throttle) {
                return false;
            }

            $host = (string) parse_url((string) $this->config->get('app.url'), PHP_URL_HOST);
            $sent = $this->mailer->send($to, "[G-Nesting] {$subject}", $body
                . "\n\n—\nSite: {$host} · ambiente: " . $this->config->get('app.env') . ' · ' . gmdate('d/m/Y H:i') . " UTC\n"
                . "Detalhes completos em storage/logs/. Este alerta não se repete por "
                . (int) ($throttle / 60) . " minutos.");

            $state['count']++;
            $state['last'][$fingerprint] = time();
            file_put_contents($this->file(), (string) json_encode($state), LOCK_EX);

            return $sent;
        } catch (Throwable $e) {
            $this->logger->warning('Falha ao enviar alerta: ' . $e->getMessage());

            return false;
        }
    }

    /** @return array{day: string, count: int, last: array<string, int>} */
    private function state(): array
    {
        $data = is_file($this->file()) ? json_decode((string) file_get_contents($this->file()), true) : null;

        return is_array($data) ? $data + ['day' => '', 'count' => 0, 'last' => []] : ['day' => '', 'count' => 0, 'last' => []];
    }

    private function file(): string
    {
        $dir = $this->config->get('paths.storage') . '/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        return $dir . '/alerts.json';
    }
}
