<?php

declare(strict_types=1);

namespace GNesting\Install;

use PDO;
use Throwable;

/**
 * Conferência do ambiente antes de instalar: o jeito mais caro de descobrir que a hospedagem
 * não serve é depois de subir tudo por FTP e chegar ao primeiro pedido.
 *
 * Cada item: ['label', 'ok', 'critical', 'detail']. Item crítico reprovado impede instalar.
 */
final class RequirementsCheck
{
    public const PHP_MIN = '8.2.0';
    /**
     * 5.7.8: colunas JSON. Muitas hospedagens compartilhadas ainda entregam MySQL 5.7. Nele as restrições CHECK do
     * esquema são aceitas mas não aplicadas, e toda regra que elas reforçam já é garantida pela aplicação (testado
     * no CI com MySQL 5.7). A lista de verificação de produção avisa para pedir MySQL 8 quando der.
     */
    private const MYSQL_MIN = '5.7.8';
    private const MARIADB_MIN = '10.6.0';

    /** Sem estas a loja não roda. */
    private const EXTENSIONS = [
        'pdo_mysql' => 'banco de dados',
        'mbstring' => 'textos com acento',
        'fileinfo' => 'conferência do tipo real dos arquivos enviados',
        'gd' => 'fotos dos produtos',
        'openssl' => 'e-mail com TLS e chaves de segurança',
        'curl' => 'Mercado Pago',
        'zlib' => 'backups compactados',
        'phar' => 'backup das fotos e arquivos de produção',
        'json' => 'configurações',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    /** @return list<array{label: string, ok: bool, critical: bool, detail: string}> */
    public function all(): array
    {
        $items = [$this->item(
            'PHP ' . self::PHP_MIN . ' ou mais novo',
            version_compare(PHP_VERSION, self::PHP_MIN, '>='),
            true,
            'encontrado ' . PHP_VERSION . ' (cPanel → Selecionar versão do PHP)',
        )];

        foreach (self::EXTENSIONS as $extension => $purpose) {
            $items[] = $this->item("Extensão {$extension}", extension_loaded($extension), true, $purpose);
        }
        $gd = function_exists('gd_info') ? gd_info() : [];
        $items[] = $this->item('GD com WebP e JPEG', !empty($gd['WebP Support']) && !empty($gd['JPEG Support']), true,
            'as fotos são convertidas para WebP');

        foreach ($this->writableDirectories() as $label => $dir) {
            $items[] = $this->item("Gravação em {$label}", is_dir($dir) && is_writable($dir), true,
                'permissão 755 pelo Gerenciador de Arquivos');
        }

        return $items;
    }

    /** @param list<array{label: string, ok: bool, critical: bool, detail: string}> $items */
    public static function blocked(array $items): bool
    {
        foreach ($items as $item) {
            if ($item['critical'] && !$item['ok']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Testa a conexão e a versão do servidor MySQL/MariaDB.
     *
     * @param array{host: string, port: int, database: string, username: string, password: string} $db
     * @return array{ok: bool, message: string}
     */
    public function database(array $db): array
    {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
                $db['username'],
                $db['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => self::explainConnectionError($e->getMessage())];
        }

        $isMaria = stripos($version, 'mariadb') !== false;
        $number = (string) preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $version);
        $minimum = $isMaria ? self::MARIADB_MIN : self::MYSQL_MIN;
        if (version_compare($number, $minimum, '<')) {
            return ['ok' => false, 'message' => sprintf('O banco é %s %s; a loja precisa de MySQL %s+ ou MariaDB %s+.',
                $isMaria ? 'MariaDB' : 'MySQL', $number, self::MYSQL_MIN, self::MARIADB_MIN)];
        }

        return ['ok' => true, 'message' => ($isMaria ? 'MariaDB ' : 'MySQL ') . $number];
    }

    /** @return array<string, string> */
    private function writableDirectories(): array
    {
        return [
            'pasta da loja (.env)' => $this->basePath,
            'storage/' => $this->basePath . '/storage',
            'public/uploads/' => $this->basePath . '/public/uploads',
        ];
    }

    /** Mensagens do PDO em português, sem repetir a senha. */
    private static function explainConnectionError(string $message): string
    {
        return match (true) {
            str_contains($message, '1045') => 'Usuário ou senha do banco incorretos (ou o usuário não foi adicionado ao banco no cPanel).',
            str_contains($message, '1049') => 'O banco informado não existe. Crie-o em cPanel → Bancos de dados MySQL.',
            str_contains($message, '1044') => 'O usuário não tem permissão neste banco. No cPanel, adicione-o ao banco com TODOS OS PRIVILÉGIOS.',
            str_contains($message, '2002'), str_contains($message, '2005') => 'Não foi possível alcançar o servidor do banco. Na hospedagem, o endereço costuma ser "localhost".',
            default => 'Não foi possível conectar ao banco: ' . mb_substr($message, 0, 200),
        };
    }

    /** @return array{label: string, ok: bool, critical: bool, detail: string} */
    private function item(string $label, bool $ok, bool $critical, string $detail): array
    {
        return ['label' => $label, 'ok' => $ok, 'critical' => $critical, 'detail' => $detail];
    }
}
