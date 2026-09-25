<?php

declare(strict_types=1);

namespace GNesting\Services\Operations;

use GNesting\Core\CanonicalUrl;
use GNesting\Core\Config;
use GNesting\Core\Database;
use Throwable;

/**
 * Lista de verificação antes (e depois) de publicar: bin/check-production.php e a tela Sistema.
 * "erro" impede a publicação; "aviso" deve ser resolvido logo.
 */
final class ProductionCheck
{
    public const ERROR = 'erro';
    public const WARNING = 'aviso';

    private const EXTENSIONS = ['pdo_mysql', 'mbstring', 'fileinfo', 'gd', 'openssl', 'curl', 'zlib', 'phar', 'json'];

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly HealthCheck $health,
        private readonly CanonicalUrl $canonical,
    ) {
    }

    /** @return list<array{label: string, ok: bool, level: string, detail: string}> */
    public function run(): array
    {
        $c = $this->config;
        $url = (string) $c->get('app.url');
        $key = (string) $c->get('app.key');
        $mail = (string) $c->get('mail.driver');
        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $ext) => !extension_loaded($ext)));
        $gd = function_exists('gd_info') ? gd_info() : [];

        $checks = [
            $this->check('PHP 8.2 ou mais novo', version_compare(PHP_VERSION, '8.2.0', '>='), self::ERROR, 'versão ' . PHP_VERSION),
            $this->check('Extensões do PHP', $missing === [], self::ERROR, $missing === [] ? 'todas presentes' : 'faltam: ' . implode(', ', $missing)),
            $this->check('GD com WebP e JPEG', !empty($gd['WebP Support']) && !empty($gd['JPEG Support']), self::ERROR, 'necessário para as fotos dos produtos'),
            $this->check('APP_ENV=production', $c->get('app.env') === 'production', self::ERROR, 'atual: ' . $c->get('app.env')),
            $this->check('APP_DEBUG=false', !$c->get('app.debug'), self::ERROR, 'com debug ligado, erros mostram detalhes internos'),
            $this->check('APP_KEY gerada', strlen($key) >= 32, self::ERROR, $key === '' ? 'vazia: rode php bin/generate-key.php' : 'ok'),
            $this->check('APP_URL com https://', str_starts_with($url, 'https://'), self::ERROR, $url),
            $this->check('Redirecionamento para https', $this->canonical->enabled(), self::WARNING, 'APP_FORCE_HTTPS'),
            $this->check('Cookie de sessão só em https', (bool) $c->get('security.session.secure_cookie'), self::ERROR, 'SESSION_SECURE_COOKIE=true'),
            $this->check('Pagamento real (Mercado Pago)', $c->get('payment.provider') === 'mercadopago', self::ERROR, 'atual: ' . $c->get('payment.provider')),
            $this->check('Credenciais do Mercado Pago', (string) $c->get('payment.mercadopago.access_token') !== '' && (string) $c->get('payment.mercadopago.webhook_secret') !== '',
                self::ERROR, 'MERCADOPAGO_ACCESS_TOKEN e MERCADOPAGO_WEBHOOK_SECRET'),
            $this->check('E-mail de verdade', in_array($mail, ['smtp', 'mail'], true) && ($mail !== 'smtp' || (string) $c->get('mail.smtp.host') !== ''),
                self::ERROR, 'MAIL_DRIVER=' . $mail . ($mail === 'smtp' ? ' · host ' . ($c->get('mail.smtp.host') ?: '(vazio)') : '')),
            $this->check('Alertas por e-mail', (string) $c->get('operations.alert_email') !== '', self::WARNING, 'ALERT_EMAIL'),
            $this->check('Token do /saude', strlen((string) $c->get('operations.health_token')) >= 20, self::WARNING, 'HEALTH_TOKEN (20+ caracteres) para ver os detalhes'),
            $this->check('expose_php desligado', !filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN), self::WARNING, 'esconde a versão do PHP (cPanel → Select PHP Version → Options)'),
            $this->check('Proprietário cadastrado', $this->ownerExists(), self::ERROR, 'php bin/create-admin.php --role=owner'),
        ];

        foreach ($this->health->run()['checks'] as $name => $result) {
            if (in_array($name, ['banco', 'gravacao', 'migrations'], true)) {
                $checks[] = $this->check(['banco' => 'Banco de dados', 'gravacao' => 'Permissões de gravação', 'migrations' => 'Migrations'][$name],
                    $result['ok'], self::ERROR, $result['detail']);
            }
        }

        return $checks;
    }

    /** @param list<array{label: string, ok: bool, level: string, detail: string}> $checks */
    public static function hasErrors(array $checks): bool
    {
        foreach ($checks as $check) {
            if (!$check['ok'] && $check['level'] === self::ERROR) {
                return true;
            }
        }

        return false;
    }

    private function ownerExists(): bool
    {
        try {
            return (bool) $this->db->pdo()->query(
                "SELECT 1 FROM admins a JOIN users u ON u.id = a.user_id WHERE a.role = 'owner' AND a.is_active = 1 AND u.status = 'active' LIMIT 1"
            )->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{label: string, ok: bool, level: string, detail: string} */
    private function check(string $label, bool $ok, string $level, string $detail): array
    {
        return ['label' => $label, 'ok' => $ok, 'level' => $level, 'detail' => $detail];
    }
}
