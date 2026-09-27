<?php

declare(strict_types=1);

namespace GNesting\Install;

use Dotenv\Dotenv;
use GNesting\Core\Bootstrap;
use GNesting\Core\Database;
use GNesting\Core\Migrations\Migrator;
use GNesting\Enums\AdminRole;
use GNesting\Services\Auth\AuthService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Instalação pelo navegador (public/instalar.php), para hospedagem sem Terminal/SSH.
 *
 * Faz o que antes eram comandos: grava o .env com chaves novas, aplica as migrations,
 * cria o proprietário e grava storage/installed.lock. Com a trava (ou um proprietário
 * no banco), recusa qualquer nova instalação: sem isso, qualquer pessoa reinstalaria a
 * loja pela URL.
 */
final class Installer
{
    public const MIN_ADMIN_PASSWORD = 12;

    /** Tabelas que precisam existir depois das migrations (conferência final). */
    private const REQUIRED_TABLES = ['schema_migrations', 'users', 'admins', 'products', 'product_variants', 'orders', 'settings', 'production_jobs'];

    private readonly EnvWriter $env;
    private readonly string $lockFile;
    /** @var list<string> */
    private array $log = [];

    public function __construct(
        private readonly string $basePath,
        ?string $envFile = null,
        ?string $lockFile = null,
    ) {
        $this->env = new EnvWriter($envFile ?? $basePath . '/.env', $basePath . '/.env.example');
        $this->lockFile = $lockFile ?? $basePath . '/storage/installed.lock';
    }

    public function requirements(): RequirementsCheck
    {
        return new RequirementsCheck($this->basePath);
    }

    public function lockFile(): string
    {
        return $this->lockFile;
    }

    /**
     * Já instalado? Duas provas independentes: a trava em disco e um proprietário no banco
     * do .env atual. A segunda cobre quem apagou a trava e tenta reinstalar por cima de uma
     * loja em operação.
     */
    public function isInstalled(): bool
    {
        if (is_file($this->lockFile)) {
            return true;
        }
        if (!$this->env->exists()) {
            return false;
        }
        try {
            $values = Dotenv::parse((string) file_get_contents($this->env->file()));
            $pdo = $this->connect([
                'host' => (string) ($values['DB_HOST'] ?? ''),
                'port' => (int) ($values['DB_PORT'] ?? 3306),
                'database' => (string) ($values['DB_DATABASE'] ?? ''),
                'username' => (string) ($values['DB_USERNAME'] ?? ''),
                'password' => (string) ($values['DB_PASSWORD'] ?? ''),
            ]);
            $hasTable = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admins'")->fetchColumn();

            return $hasTable > 0 && (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'owner'")->fetchColumn() > 0;
        } catch (Throwable) {
            // .env de uma tentativa anterior com credenciais erradas: deixa instalar de novo
            return false;
        }
    }

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string} $db
     * @return array{ok: bool, message: string}
     */
    public function testDatabase(array $db): array
    {
        return $this->requirements()->database($db);
    }

    /**
     * Executa a instalação inteira.
     *
     * @param array<string, string> $data campos do formulário (validados por InstallForm)
     * @return list<string> o que foi feito, para mostrar na tela
     */
    public function run(array $data): array
    {
        $this->log = [];
        if ($this->isInstalled()) {
            throw new RuntimeException('A loja já está instalada. Para reinstalar de propósito, apague storage/installed.lock e o banco.');
        }

        $dbConfig = [
            'host' => $data['db_host'],
            'port' => (int) $data['db_port'],
            'database' => $data['db_database'],
            'username' => $data['db_username'],
            'password' => $data['db_password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];

        $this->writeEnv($data);
        $db = new Database($dbConfig);
        $this->migrate($db, ($data['sample_data'] ?? '') === '1');
        $this->verifySchema($db->pdo());
        $this->createOwner($db, $data);
        $this->lock();

        return $this->log;
    }

    public static function isProductionUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return !in_array($host, ['localhost', '127.0.0.1', '::1'], true) && !str_ends_with($host, '.test') && !str_ends_with($host, '.local');
    }

    /** @param array<string, string> $data */
    private function writeEnv(array $data): void
    {
        $url = rtrim($data['app_url'], '/');
        $production = self::isProductionUrl($url);
        $smtp = $data['mail_host'] !== '';
        $mercadoPago = $data['mp_access_token'] !== '';
        $from = filter_var($data['mail_username'], FILTER_VALIDATE_EMAIL) !== false ? $data['mail_username'] : $data['admin_email'];

        $this->env->createFromExample([
            // Produção nunca mostra erro na tela: o texto do erro entrega caminhos e versões
            'APP_ENV' => $production ? 'production' : 'local',
            'APP_DEBUG' => $production ? 'false' : 'true',
            'APP_URL' => $url,
            'APP_KEY' => EnvWriter::generateKey(),
            'DB_HOST' => $data['db_host'],
            'DB_PORT' => $data['db_port'],
            'DB_DATABASE' => $data['db_database'],
            'DB_USERNAME' => $data['db_username'],
            'DB_PASSWORD' => $data['db_password'],
            'SESSION_SECURE_COOKIE' => str_starts_with($url, 'https://') ? 'true' : 'false',
            // Sem SMTP: a função mail() da hospedagem em produção; no computador, grava em storage/logs
            'MAIL_DRIVER' => $smtp ? 'smtp' : ($production ? 'mail' : 'log'),
            'MAIL_HOST' => $data['mail_host'],
            'MAIL_PORT' => $data['mail_port'] !== '' ? $data['mail_port'] : '587',
            'MAIL_ENCRYPTION' => $data['mail_port'] === '465' ? 'ssl' : 'tls',
            'MAIL_USERNAME' => $data['mail_username'],
            'MAIL_PASSWORD' => $data['mail_password'],
            'MAIL_FROM_ADDRESS' => $from,
            // O pagamento simulado nunca vai para produção, nem sem credenciais: a lista de verificação acusa
            'PAYMENT_PROVIDER' => $production || $mercadoPago ? 'mercadopago' : 'simulado',
            'MERCADOPAGO_ACCESS_TOKEN' => $data['mp_access_token'],
            'MERCADOPAGO_WEBHOOK_SECRET' => $data['mp_webhook_secret'],
            'SHIPPING_ORIGIN_STATE' => $data['shipping_origin_state'],
            'HEALTH_TOKEN' => bin2hex(random_bytes(24)),
            'CRON_TOKEN' => bin2hex(random_bytes(20)),
            'ALERT_EMAIL' => $data['admin_email'],
        ]);

        $this->add('Arquivo .env gravado com chaves novas (' . ($production ? 'produção' : 'desenvolvimento') . ').');
    }

    private function migrate(Database $db, bool $sampleData): void
    {
        $base = $this->basePath . '/database';
        $migrator = new Migrator($db->pdo(), $base . '/migrations', $base . '/seeds', function (string $line): void {
            if (str_starts_with($line, 'Aplicando ')) {
                $this->add(rtrim(substr($line, 10), '.') . ' aplicada.');
            }
        });
        $applied = $migrator->migrate();
        $this->add($applied === [] ? 'Nenhuma migration pendente.' : count($applied) . ' migration(s) aplicada(s).');

        if ($sampleData) {
            $migrator->seed();
            $this->add('Produtos e categorias de exemplo instalados.');
        }
    }

    private function verifySchema(PDO $pdo): void
    {
        $existing = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff(self::REQUIRED_TABLES, array_map('strtolower', array_map('strval', $existing)));
        if ($missing !== []) {
            throw new RuntimeException('O banco ficou incompleto depois das migrations (faltam: ' . implode(', ', $missing) . '). '
                . 'Apague as tabelas pelo phpMyAdmin e tente de novo.');
        }
        $this->add('Banco conferido: ' . count($existing) . ' tabelas.');
    }

    /** @param array<string, string> $data */
    private function createOwner(Database $db, array $data): void
    {
        // Usa a conexão recém-configurada; o resto do container sobe com o .env gravado
        $container = Bootstrap::createContainer($this->basePath);
        $container->instance(Database::class, $db);
        $id = $container->get(AuthService::class)->createAdmin($data['admin_name'], $data['admin_email'], $data['admin_password'], AdminRole::Owner);
        $this->add("Proprietário #{$id} criado: " . AuthService::normalizeEmail($data['admin_email']) . '.');
    }

    private function lock(): void
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $content = 'Instalado em ' . gmdate('d/m/Y H:i') . " UTC.\nNão apague: é este arquivo que impede alguém de reinstalar a loja pela URL.\n";
        if (@file_put_contents($this->lockFile, $content, LOCK_EX) === false) {
            throw new RuntimeException('A instalação terminou, mas não foi possível gravar storage/installed.lock. '
                . 'Crie esse arquivo pelo Gerenciador de Arquivos (qualquer conteúdo).');
        }
        $this->add('Instalação concluída e travada (storage/installed.lock).');
    }

    /** @param array{host: string, port: int, database: string, username: string, password: string} $db */
    private function connect(array $db): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
            $db['username'],
            $db['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );
    }

    private function add(string $line): void
    {
        $this->log[] = $line;
    }
}
