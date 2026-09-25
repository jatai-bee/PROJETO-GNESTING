<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GNesting\Core\Config;
use GNesting\Core\Database;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Router;
use GNesting\Enums\AdminRole;
use GNesting\Services\Operations\BackupService;
use GNesting\Services\Operations\CronRunner;
use GNesting\Services\Operations\DatabaseDumper;
use GNesting\Services\Operations\Housekeeping;
use GNesting\Services\Operations\ProductionCheck;
use GNesting\Tests\Support\TestController;
use GNesting\Tests\Support\TestFiles;

/** Etapa 12: HTTPS/host canônico, proxies, manutenção, /saude, cron, backups, alertas e lista de verificação. */
final class OperationsTest extends HttpTestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = TestFiles::tempDir('gn-storage');
        foreach (['cache', 'sessions', 'logs', 'private/production_files', 'backups', 'uploads'] as $dir) {
            mkdir($this->storage . '/' . $dir, 0777, true);
        }
        $this->configOverrides = [
            'paths.storage' => $this->storage,
            'paths.logs' => $this->storage . '/logs',
            'paths.backups' => $this->storage . '/backups',
            'paths.uploads' => $this->storage . '/uploads',
            'operations.alert_email' => 'alertas@gnesting.test',
            'operations.health_token' => str_repeat('t', 32),
        ];
        parent::setUp();
    }

    private function production(): void
    {
        $this->configOverrides += ['app.env' => 'production', 'app.url' => 'https://loja.test'];
        $this->newBrowser();
    }

    /** @param array<string, string> $server */
    private function raw(string $method, string $uri, array $server = [], array $body = []): Response
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->send(new Request($method, $path, $query, $body, $this->cookies(),
            $server + ['REMOTE_ADDR' => $this->ip, 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'loja.test', 'HTTPS' => 'on']));
    }

    // ---- HTTPS e host canônico ----------------------------------------------------------

    public function testProductionRedirectsToHttpsOnTheCanonicalHost(): void
    {
        $this->production();

        $http = $this->raw('GET', '/produtos?ordem=preco', ['HTTPS' => 'off']);
        self::assertSame(301, $http->status());
        self::assertSame('https://loja.test/produtos?ordem=preco', $http->header('Location'));

        $www = $this->raw('GET', '/', ['HTTP_HOST' => 'www.loja.test']);
        self::assertSame('https://loja.test/', $www->header('Location'));

        self::assertSame(308, $this->raw('POST', '/carrinho/itens', ['HTTPS' => 'off'])->status(), 'POST preserva o método');

        $ok = $this->raw('GET', '/');
        self::assertSame(200, $ok->status());
        self::assertStringContainsString('max-age=31536000', (string) $ok->header('Strict-Transport-Security'));
    }

    public function testForwardedProtoIsHonouredOnlyFromTrustedProxies(): void
    {
        $this->configOverrides['operations.trusted_proxies'] = ['173.245.48.0/20'];
        $this->production();
        $behindCloudflare = ['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '173.245.48.7'];

        self::assertSame(200, $this->raw('GET', '/', $behindCloudflare)->status());
        self::assertSame(301, $this->raw('GET', '/', ['REMOTE_ADDR' => '6.6.6.6'] + $behindCloudflare)->status(), 'Cabeçalho forjado');
    }

    public function testNoRedirectOutsideProductionOrWhenDisabled(): void
    {
        self::assertSame(200, $this->raw('GET', '/', ['HTTPS' => 'off'])->status());

        $this->configOverrides['operations.force_https'] = false;
        $this->production();
        self::assertSame(200, $this->raw('GET', '/', ['HTTPS' => 'off'])->status());
    }

    // ---- Manutenção ------------------------------------------------------------------------

    public function testMaintenanceModeBlocksEverythingExceptTheBypassAndHealth(): void
    {
        $secret = $this->container->get(\GNesting\Core\Maintenance::class)->enable('Voltamos às 15h.');

        $page = $this->get('/');
        self::assertSame(503, $page->status());
        self::assertSame('600', $page->header('Retry-After'));
        self::assertStringContainsString('Voltamos às 15h.', $page->body());
        self::assertSame(503, $this->get('/admin/login')->status());
        self::assertSame(503, $this->postRaw('/webhooks/pagamento/mercadopago')->status(), 'Webhook volta depois (o provedor reenvia)');
        self::assertSame(503, $this->get('/', ['manutencao' => 'errado'])->status());

        $health = $this->get('/saude');
        self::assertSame(200, $health->status());
        self::assertSame('atencao', json_decode($health->body(), true)['status']);

        $pass = $this->get('/produtos', ['manutencao' => $secret]);
        self::assertSame('/produtos', $pass->header('Location'));
        self::assertSame(200, $this->get('/')->status(), 'Com o cookie de passagem');

        $this->newBrowser();
        self::assertSame(503, $this->get('/')->status());
    }

    public function testOwnerTogglesMaintenanceFromThePanelAndKeepsAccess(): void
    {
        $this->loginAdmin(AdminRole::Owner);
        $this->post('/admin/sistema/manutencao', ['acao' => 'ligar', 'mensagem' => 'Atualizando']);
        self::assertSame(200, $this->get('/admin/sistema')->status(), 'Quem ligou continua entrando');
        self::assertStringContainsString('Desligar e voltar ao ar', $this->get('/admin/sistema')->body());

        $owner = $this->currentBrowser();
        $this->newBrowser();
        self::assertSame(503, $this->get('/')->status());

        $this->useBrowser($owner);
        $this->post('/admin/sistema/manutencao', ['acao' => 'desligar']);
        $this->newBrowser();
        self::assertSame(200, $this->get('/')->status());
        self::assertSame(2, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'maintenance'"));
    }

    // ---- /saude e cron ---------------------------------------------------------------------

    public function testHealthEndpointHidesDetailsWithoutTokenAndOpensNoSession(): void
    {
        $plain = $this->get('/saude');
        self::assertSame(['status' => 'atencao'], json_decode($plain->body(), true), 'Cron e backup ainda não rodaram');
        self::assertSame([], $plain->cookies(), 'Sem sessão nem carrinho');
        self::assertSame('noindex', $plain->header('X-Robots-Tag'));

        $detailed = json_decode($this->get('/saude', ['token' => str_repeat('t', 32)])->body(), true);
        self::assertTrue($detailed['checks']['banco']['ok']);
        self::assertTrue($detailed['checks']['gravacao']['ok']);
        self::assertTrue($detailed['checks']['migrations']['ok']);
        self::assertFalse($detailed['checks']['cron']['ok']);
        self::assertStringContainsString('nunca rodou', $detailed['checks']['cron']['detail']);
        self::assertSame(['status' => 'atencao'], json_decode($this->get('/saude', ['token' => 'errado'])->body(), true));

        $this->configOverrides['operations.backup.hour'] = 0;
        $this->newBrowser();
        $this->container->get(CronRunner::class)->run();
        $after = json_decode($this->get('/saude', ['token' => str_repeat('t', 32)])->body(), true);
        self::assertTrue($after['checks']['cron']['ok'], $after['checks']['cron']['detail']);
        self::assertTrue($after['checks']['backup']['ok'], $after['checks']['backup']['detail']);
        self::assertSame('ok', $after['status']);
    }

    public function testCronIsolatesFailingTasksAndAlertsOnce(): void
    {
        file_put_contents($this->storage . '/arquivo', 'x');
        $this->configOverrides['paths.backups'] = $this->storage . '/arquivo/backups'; // impossível criar
        $this->configOverrides['operations.backup.hour'] = 0;
        $this->newBrowser();

        $results = $this->container->get(CronRunner::class)->run();
        self::assertFalse($results['backup']['ok']);
        self::assertTrue($results['pedidos_nao_pagos']['ok'], 'As outras tarefas rodaram');
        self::assertTrue($results['sessoes_expiradas']['ok']);
        $heartbeat = json_decode((string) file_get_contents($this->storage . '/cache/cron.json'), true);
        self::assertFalse($heartbeat['tasks']['backup']['ok']);

        $this->container->get(CronRunner::class)->run();
        $alerts = array_filter($this->mail->sent(), fn (array $m) => $m['to'] === 'alertas@gnesting.test');
        self::assertCount(1, $alerts, 'Mesmo alerta não se repete dentro de 1 hora');
        self::assertStringContainsString('Falha no cron: backup', array_values($alerts)[0]['subject']);
    }

    public function testUnexpectedErrorsSendAThrottledAlert(): void
    {
        $this->container->get(Router::class)->get('/__erro', [TestController::class, 'explode']);
        $response = $this->get('/__erro');
        self::assertSame(500, $response->status());
        $this->get('/__erro');

        $alerts = array_values(array_filter($this->mail->sent(), fn (array $m) => $m['to'] === 'alertas@gnesting.test'));
        self::assertCount(1, $alerts);
        self::assertMatchesRegularExpression('/Erro 500 \([A-F0-9]{6}\)/', $alerts[0]['subject']);
        self::assertStringContainsString('GET /__erro', $alerts[0]['body']);
    }

    public function testHousekeepingRemovesOldLogsAndSessions(): void
    {
        $old = time() - 40 * 86400;
        foreach (['logs/app-2026-01-01.log', 'logs/mail-2026-01-01.log', 'logs/php-errors.log', 'sessions/sess_velha'] as $file) {
            file_put_contents($this->storage . '/' . $file, 'x');
            touch($this->storage . '/' . $file, $old);
        }
        file_put_contents($this->storage . '/logs/app-hoje.log', 'x');
        file_put_contents($this->storage . '/sessions/sess_nova', 'x');

        $housekeeping = $this->container->get(Housekeeping::class);
        self::assertSame(2, $housekeeping->purgeOldLogs());
        self::assertSame(1, $housekeeping->purgeExpiredSessions());
        self::assertFileExists($this->storage . '/logs/php-errors.log', 'Arquivo ativo não é apagado');
        self::assertFileExists($this->storage . '/logs/app-hoje.log');
        self::assertFileExists($this->storage . '/sessions/sess_nova');
    }

    // ---- Backups -----------------------------------------------------------------------------

    public function testBackupRestoresIntoAnEmptyDatabaseWithIdenticalData(): void
    {
        // Dado com caracteres que costumam quebrar dumps
        $this->db->pdo()->prepare("UPDATE products SET description = ? WHERE slug = 'relogio-geometrico-g-nesting'")
            ->execute(["Texto com 'aspas', \"duplas\", barra \\ ponto-e-vírgula; quebra\nde linha, emoji 🪵 e -- comentário"]);
        file_put_contents($this->storage . '/uploads/foto.webp', 'imagem');
        file_put_contents($this->storage . '/private/production_files/peca.nc', 'G0 X0');

        $backups = $this->container->get(BackupService::class);
        $manifest = $backups->create(true);
        self::assertSame(['database.sql.gz', 'files.tar.gz'], array_keys($manifest['files']));
        self::assertGreaterThan(40, count($manifest['tables']));

        // Restaura num banco separado (o de teste está dentro de uma transação)
        $config = $this->container->get(Config::class)->get('database');
        $scratchName = $config['database'] . '_restauracao';
        $this->db->serverConnection()->exec("DROP DATABASE IF EXISTS `{$scratchName}`");
        $this->db->serverConnection()->exec("CREATE DATABASE `{$scratchName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $scratch = new Database(['database' => $scratchName] + $config);
            $restorer = new BackupService(new DatabaseDumper($scratch), $this->container->get(Config::class));

            file_put_contents($this->storage . '/uploads/intruso.php', 'x');
            unlink($this->storage . '/uploads/foto.webp');
            $log = [];
            $restorer->restore($manifest['name'], true, function (string $line) use (&$log): void {
                $log[] = $line;
            });

            foreach ($manifest['tables'] as $table => $rows) {
                self::assertSame($rows, (int) $scratch->pdo()->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn(), $table);
            }
            self::assertSame(
                $this->fetchValue("SELECT description FROM products WHERE slug = 'relogio-geometrico-g-nesting'"),
                $scratch->pdo()->query("SELECT description FROM products WHERE slug = 'relogio-geometrico-g-nesting'")->fetchColumn(),
            );
            self::assertSame('imagem', file_get_contents($this->storage . '/uploads/foto.webp'));
            self::assertFileDoesNotExist($this->storage . '/uploads/intruso.php', 'Arquivos espelham o backup');
            self::assertSame('G0 X0', file_get_contents($this->storage . '/private/production_files/peca.nc'));
            self::assertStringContainsString('SHA-256', $log[0]);

            // Arquivo adulterado: nada é restaurado
            $gz = $backups->directory() . '/' . $manifest['name'] . '/database.sql.gz';
            file_put_contents($gz, 'corrompido', FILE_APPEND);
            try {
                $restorer->restore($manifest['name'], false, fn (string $l) => null);
                self::fail('Deveria recusar');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('corrompido', $e->getMessage());
            }
        } finally {
            $this->db->serverConnection()->exec("DROP DATABASE IF EXISTS `{$scratchName}`");
        }
    }

    public function testBackupScheduleAndRetention(): void
    {
        $this->configOverrides += ['operations.backup.keep_daily' => 3, 'operations.backup.keep_monthly' => 2, 'operations.backup.hour' => 3];
        $this->newBrowser();
        $backups = $this->container->get(BackupService::class);
        $utc = new DateTimeZone('UTC');

        // 02:30 em São Paulo = antes da hora; 06:30 UTC = 03:30 em São Paulo
        self::assertFalse($backups->isDue(new DateTimeImmutable('2026-09-25 05:30:00', $utc)));
        self::assertTrue($backups->isDue(new DateTimeImmutable('2026-09-25 06:30:00', $utc)));

        foreach (['2026-06-02_060000', '2026-06-20_060000', '2026-07-01_060000', '2026-08-01_060000', '2026-08-15_060000',
                     '2026-09-22_060000', '2026-09-23_060000', '2026-09-24_060000'] as $name) {
            mkdir($backups->directory() . '/' . $name);
            file_put_contents($backups->directory() . '/' . $name . '/manifest.json', (string) json_encode([
                'name' => $name, 'created_at_utc' => str_replace('_', ' ', substr($name, 0, 10)) . ' 06:00:00', 'files' => [], 'rows' => 0,
            ]));
        }
        $backups->create(false, new DateTimeImmutable('2026-09-25 06:30:00', $utc));
        self::assertFalse($backups->isDue(new DateTimeImmutable('2026-09-25 20:00:00', $utc)), 'Já fez o de hoje');
        self::assertTrue($backups->isDue(new DateTimeImmutable('2026-09-26 07:00:00', $utc)));

        $removed = $backups->prune();
        self::assertSame(['2026-09-25_063000', '2026-09-24_060000', '2026-09-23_060000', '2026-09-22_060000', '2026-08-01_060000'],
            array_column($backups->list(), 'name'), 'Últimos 3 + o primeiro de cada um dos 2 últimos meses (setembro e agosto)');
        self::assertContains('2026-06-02_060000', $removed);
    }

    public function testOwnerDownloadsBackupFromThePanel(): void
    {
        $name = $this->container->get(BackupService::class)->create(false)['name'];
        $this->loginAdmin(AdminRole::Owner);
        $page = $this->get('/admin/sistema')->body();
        self::assertStringContainsString($name, $page);
        self::assertStringContainsString('Lista de verificação de produção', $page);

        $download = $this->get("/admin/sistema/backups/{$name}/banco");
        self::assertStringContainsString("gnesting-{$name}-database.sql.gz", (string) $download->header('Content-Disposition'));
        self::assertNotNull($download->filePath());
        self::assertSame(404, $this->get("/admin/sistema/backups/{$name}/arquivos")->status(), 'Backup sem arquivos');
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'export' AND entity_type = 'backup'"));
    }

    // ---- Lista de verificação ----------------------------------------------------------------

    public function testProductionChecklist(): void
    {
        $checks = $this->container->get(ProductionCheck::class)->run();
        self::assertTrue(ProductionCheck::hasErrors($checks), 'Ambiente de teste não está pronto para produção');
        $labels = array_column(array_filter($checks, fn ($c) => !$c['ok']), 'label');
        self::assertContains('APP_ENV=production', $labels);
        self::assertContains('Pagamento real (Mercado Pago)', $labels);

        $this->configOverrides += [
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://loja.test', 'app.key' => str_repeat('k', 64),
            'security.session.secure_cookie' => true, 'payment.provider' => 'mercadopago',
            'payment.mercadopago.access_token' => 'APP_USR-x', 'payment.mercadopago.webhook_secret' => 'segredo',
            'mail.driver' => 'smtp', 'mail.smtp.host' => 'smtp.loja.test',
        ];
        $this->loginAdmin(AdminRole::Owner);
        $ready = $this->container->get(ProductionCheck::class)->run();
        self::assertFalse(ProductionCheck::hasErrors($ready), implode('; ', array_map(
            fn ($c) => $c['label'] . ': ' . $c['detail'],
            array_filter($ready, fn ($c) => !$c['ok'] && $c['level'] === ProductionCheck::ERROR),
        )));
    }
}
