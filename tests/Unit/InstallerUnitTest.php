<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use Dotenv\Dotenv;
use GNesting\Install\EnvWriter;
use GNesting\Install\InstallForm;
use GNesting\Install\Installer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Etapa 13: gravação do .env e validação do formulário do instalador. */
final class InstallerUnitTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function trickyValues(): iterable
    {
        yield 'simples' => ['usuario_gnesting'];
        yield 'espaço' => ['G-Nesting Loja'];
        yield 'cifrão e barra' => ['a$b\\c$1\\1'];
        yield 'aspas' => ['diz "oi" e \'tchau\''];
        yield 'variável' => ['${HOME}x'];
        yield 'cerquilha' => ['#nao-e-comentario'];
        yield 'url' => ['https://loja.test/x?y=1'];
    }

    /** Senhas com $, \, aspas e # voltam idênticas pelo Dotenv (antes eram gravadas truncadas). */
    #[DataProvider('trickyValues')]
    public function testEnvValuesRoundTripThroughDotenv(string $value): void
    {
        $writer = new EnvWriter('x', 'y');
        $content = $writer->apply("# comentário\nDB_PASSWORD=antiga\nOUTRA=1\n", 'DB_PASSWORD', $value);

        self::assertSame($value, Dotenv::parse($content)['DB_PASSWORD']);
        self::assertStringContainsString("# comentário\n", $content);
        self::assertSame('1', Dotenv::parse($content)['OUTRA'], 'As outras linhas ficam como estão');
    }

    public function testCreatesEnvFromExampleKeepingCommentsAndAppendingNewKeys(): void
    {
        $dir = sys_get_temp_dir() . '/gn-env-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/.env.example', "# Aplicação\nAPP_ENV=local\nAPP_KEY=\n");
        try {
            $writer = new EnvWriter($dir . '/.env', $dir . '/.env.example');
            $writer->createFromExample(['APP_ENV' => 'production', 'APP_KEY' => EnvWriter::generateKey(), 'NOVA' => 'sim']);
            $content = (string) file_get_contents($dir . '/.env');
            $values = Dotenv::parse($content);

            self::assertStringStartsWith('# Aplicação', $content);
            self::assertSame('production', $values['APP_ENV']);
            self::assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $values['APP_KEY']);
            self::assertSame('sim', $values['NOVA']);
        } finally {
            array_map('unlink', array_filter(glob($dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
            rmdir($dir);
        }
    }

    /** @return array<string, string> */
    private function validForm(array $overrides = []): array
    {
        return InstallForm::fromInput($overrides + [
            'app_url' => 'https://gnesting.com.br/',
            'db_host' => 'localhost', 'db_port' => '3306', 'db_database' => 'usuario_gnesting',
            'db_username' => 'usuario_gnapp', 'db_password' => ' senha com espaço ',
            'admin_name' => 'Ana', 'admin_email' => 'ANA@GNESTING.COM.BR',
            'admin_password' => 'senha-muito-forte', 'admin_password_confirmation' => 'senha-muito-forte',
            'shipping_origin_state' => 'ba',
        ], 'https://gnesting.com.br');
    }

    public function testValidFormIsNormalized(): void
    {
        $data = $this->validForm();

        self::assertSame([], InstallForm::validate($data));
        self::assertSame('https://gnesting.com.br', $data['app_url'], 'Sem barra no fim');
        self::assertSame('ana@gnesting.com.br', $data['admin_email']);
        self::assertSame('BA', $data['shipping_origin_state']);
        self::assertSame(' senha com espaço ', $data['db_password'], 'Senhas ficam como digitadas');
    }

    public function testFormRejectsWhatWouldBreakTheInstallation(): void
    {
        $errors = InstallForm::validate($this->validForm([
            'app_url' => 'http://gnesting.com.br',            // produção sem https
            'db_database' => 'banco; DROP',                      // nome inválido
            'admin_password' => 'curta', 'admin_password_confirmation' => 'curta',
            'shipping_origin_state' => 'XX',
            'mail_host' => 'mail.gnesting.com.br',               // SMTP sem usuário/senha
            'mp_access_token' => 'APP_USR-1',                    // token sem a assinatura do webhook
            'sample_data' => '1',                                // exemplos em produção
        ]));

        self::assertSame(['app_url', 'db_database', 'admin_password', 'shipping_origin_state', 'mail_username', 'mp_webhook_secret', 'sample_data'],
            array_keys($errors));
        self::assertStringContainsString('https://', $errors['app_url']);

        $mismatch = InstallForm::validate($this->validForm(['admin_password_confirmation' => 'outra-senha-forte']));
        self::assertArrayHasKey('admin_password_confirmation', $mismatch);
    }

    public function testLocalAddressesAreNotProduction(): void
    {
        foreach (['http://localhost:8000', 'http://127.0.0.1', 'http://gnesting.test', 'http://loja.local'] as $url) {
            self::assertFalse(Installer::isProductionUrl($url), $url);
        }
        self::assertTrue(Installer::isProductionUrl('https://gnesting.com.br'));
        self::assertSame([], InstallForm::validate($this->validForm(['app_url' => 'http://localhost:8000', 'sample_data' => '1'])),
            'No computador: http e produtos de exemplo são permitidos');
    }
}
