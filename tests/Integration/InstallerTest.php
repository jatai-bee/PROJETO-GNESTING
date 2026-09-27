<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use Dotenv\Dotenv;
use GNesting\Core\Bootstrap;
use GNesting\Core\Config;
use GNesting\Core\Database;
use GNesting\Install\InstallForm;
use GNesting\Install\Installer;
use GNesting\Services\Demo\DemoDataService;
use GNesting\Tests\Support\TestFiles;
use PDO;
use RuntimeException;

/**
 * Etapa 13: instalação pelo navegador, contra um banco vazio de verdade.
 * O .env e a trava vão para uma pasta temporária; o banco é <teste>_instalacao, apagado no fim.
 */
final class InstallerTest extends IntegrationTestCase
{
    private string $dir;
    private string $scratch;
    /** @var array<string, mixed> */
    private array $dbConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = TestFiles::tempDir('gn-install');
        $this->dbConfig = $this->container->get(Config::class)->get('database');
        $this->scratch = $this->dbConfig['database'] . '_instalacao';
        $this->server()->exec("DROP DATABASE IF EXISTS `{$this->scratch}`");
        $this->server()->exec("CREATE DATABASE `{$this->scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    protected function tearDown(): void
    {
        $this->server()->exec("DROP DATABASE IF EXISTS `{$this->scratch}`");
        TestFiles::cleanup();
        parent::tearDown();
    }

    private function server(): PDO
    {
        return $this->db->serverConnection();
    }

    private function installer(): Installer
    {
        return new Installer(dirname(__DIR__, 2), $this->dir . '/.env', $this->dir . '/installed.lock');
    }

    /** @return array<string, string> */
    private function form(string $url, array $overrides = []): array
    {
        return InstallForm::fromInput($overrides + [
            'app_url' => $url,
            'db_host' => (string) $this->dbConfig['host'], 'db_port' => (string) $this->dbConfig['port'],
            'db_database' => $this->scratch, 'db_username' => (string) $this->dbConfig['username'],
            'db_password' => (string) $this->dbConfig['password'],
            'admin_name' => 'Dona da Loja', 'admin_email' => 'dona@gnesting.test',
            'admin_password' => 'senha-muito-forte', 'admin_password_confirmation' => 'senha-muito-forte',
            'shipping_origin_state' => 'SP',
        ], $url);
    }

    private function scratchPdo(): PDO
    {
        return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->dbConfig['host'], $this->dbConfig['port'], $this->scratch),
            $this->dbConfig['username'], $this->dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function testProductionInstallWritesEnvCreatesSchemaAndOwnerAndLocksItself(): void
    {
        $installer = $this->installer();
        self::assertFalse($installer->isInstalled());
        $form = $this->form('https://loja.gnesting.test.br', ['mp_access_token' => 'APP_USR-token', 'mp_webhook_secret' => 'segredo']);
        self::assertSame([], InstallForm::validate($form));
        self::assertTrue($installer->testDatabase(['host' => $form['db_host'], 'port' => (int) $form['db_port'],
            'database' => $form['db_database'], 'username' => $form['db_username'], 'password' => $form['db_password']])['ok']);

        $log = $installer->run($form);

        self::assertStringContainsString('produção', $log[0]);
        self::assertStringContainsString('travada', (string) end($log));

        $env = Dotenv::parse((string) file_get_contents($this->dir . '/.env'));
        self::assertSame('production', $env['APP_ENV']);
        self::assertSame('false', $env['APP_DEBUG']);
        self::assertSame('https://loja.gnesting.test.br', $env['APP_URL']);
        self::assertStringStartsWith('base64:', $env['APP_KEY']);
        self::assertSame($this->scratch, $env['DB_DATABASE']);
        self::assertSame('true', $env['SESSION_SECURE_COOKIE']);
        self::assertSame('mercadopago', $env['PAYMENT_PROVIDER']);
        self::assertSame('APP_USR-token', $env['MERCADOPAGO_ACCESS_TOKEN']);
        self::assertSame('mail', $env['MAIL_DRIVER'], 'Sem SMTP informado: mail() da hospedagem');
        self::assertSame('SP', $env['SHIPPING_ORIGIN_STATE']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $env['CRON_TOKEN']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $env['HEALTH_TOKEN']);
        self::assertSame('dona@gnesting.test', $env['ALERT_EMAIL']);
        self::assertStringContainsString('# Banco de dados', (string) file_get_contents($this->dir . '/.env'), 'Comentários do modelo preservados');

        $pdo = $this->scratchPdo();
        $migrations = count(glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: []);
        self::assertSame($migrations, (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration NOT LIKE 'seed:%'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(), 'Produção começa sem produtos de exemplo');
        $owner = $pdo->query("SELECT u.email, u.password_hash, a.role FROM admins a JOIN users u ON u.id = a.user_id")->fetchAll();
        self::assertCount(1, $owner);
        self::assertSame(['dona@gnesting.test', 'owner'], [$owner[0]['email'], $owner[0]['role']]);
        self::assertTrue(password_verify('senha-muito-forte', $owner[0]['password_hash']));

        self::assertFileExists($this->dir . '/installed.lock');
        self::assertTrue($installer->isInstalled());
        try {
            $installer->run($form);
            self::fail('Reinstalar por cima de uma loja precisa ser recusado');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('já está instalada', $e->getMessage());
        }

        // Apagar a trava não basta: há um proprietário no banco do .env
        unlink($this->dir . '/installed.lock');
        self::assertTrue($installer->isInstalled());
    }

    public function testLocalInstallCanBringDemoDataThatIsRemovedWithoutLeftovers(): void
    {
        $installer = $this->installer();
        $log = $installer->run($this->form('http://localhost:8000', ['sample_data' => '1']));

        $env = Dotenv::parse((string) file_get_contents($this->dir . '/.env'));
        self::assertSame(['local', 'true', 'simulado', 'log', 'false'],
            [$env['APP_ENV'], $env['APP_DEBUG'], $env['PAYMENT_PROVIDER'], $env['MAIL_DRIVER'], $env['SESSION_SECURE_COOKIE']]);
        self::assertStringContainsString('Dados de demonstração instalados', implode("
", $log));

        // Volume mínimo pedido na reformulação (docs/19): 5 categorias, 15 subcategorias, 30 produtos, 15 clientes, 25 pedidos
        $pdo = $this->scratchPdo();
        $count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        self::assertGreaterThanOrEqual(5, $count('SELECT COUNT(*) FROM categories WHERE parent_id IS NULL'));
        self::assertGreaterThanOrEqual(15, $count('SELECT COUNT(*) FROM categories WHERE parent_id IS NOT NULL'));
        self::assertGreaterThanOrEqual(30, $count('SELECT COUNT(*) FROM products'));
        self::assertGreaterThanOrEqual(15, $count('SELECT COUNT(*) FROM customers'));
        self::assertGreaterThanOrEqual(25, $count('SELECT COUNT(*) FROM orders'));
        self::assertGreaterThanOrEqual(10, $count('SELECT COUNT(DISTINCT status) FROM orders'), 'Pedidos em todas as etapas');
        self::assertGreaterThan(0, $count('SELECT COUNT(*) FROM product_images'));
        self::assertSame(['GANCHO-MET', 'MDF-BRA-06', 'VERNIZ-PU'], $pdo->query(
            'SELECT code FROM materials WHERE stock_qty <= reorder_level ORDER BY code'
        )->fetchAll(PDO::FETCH_COLUMN), 'Consumo dos pedidos acertado: só os alertas planejados');
        $files = $pdo->query('SELECT path FROM product_images LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);

        // Remoção: só o que a demonstração criou (o proprietário fica)
        $container = Bootstrap::createContainer(dirname(__DIR__, 2));
        $container->instance(Database::class, new Database(['database' => $this->scratch] + $this->dbConfig));
        $removed = $container->get(DemoDataService::class)->remove();
        self::assertGreaterThanOrEqual(30, $removed['produtos']);
        foreach (['products', 'orders', 'customers', 'demo_records', 'product_images'] as $table) {
            self::assertSame(0, $count("SELECT COUNT(*) FROM {$table}"), $table);
        }
        self::assertSame(1, $count("SELECT COUNT(*) FROM users WHERE email = 'dona@gnesting.test'"));
        foreach ($files as $path) {
            self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/public/uploads/' . $path);
        }
    }

    public function testWrongCredentialsOrMissingDatabaseAreExplainedWithoutTouchingAnything(): void
    {
        $installer = $this->installer();
        $base = ['host' => (string) $this->dbConfig['host'], 'port' => (int) $this->dbConfig['port'],
            'username' => (string) $this->dbConfig['username'], 'password' => (string) $this->dbConfig['password']];

        $missing = $installer->testDatabase($base + ['database' => 'banco_que_nao_existe_' . bin2hex(random_bytes(3))]);
        self::assertFalse($missing['ok']);
        self::assertStringContainsString('não existe', $missing['message']);

        $denied = $installer->testDatabase(['username' => 'usuario_inexistente', 'password' => 'errada', 'database' => $this->scratch] + $base);
        self::assertFalse($denied['ok']);
        self::assertStringNotContainsString('errada', $denied['message'], 'A senha nunca aparece na mensagem');

        self::assertFileDoesNotExist($this->dir . '/.env');
        self::assertFalse($installer->isInstalled());
    }
}
