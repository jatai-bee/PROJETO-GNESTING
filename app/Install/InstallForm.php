<?php

declare(strict_types=1);

namespace GNesting\Install;

use GNesting\Helpers\ZipCode;

/**
 * Campos e validação do formulário do instalador. Barrar aqui custa uma mensagem clara;
 * deixar passar custa uma instalação que para no meio com o banco já criado.
 */
final class InstallForm
{
    /** @return array<string, string> valores iniciais */
    public static function defaults(string $guessedUrl): array
    {
        return [
            'app_url' => $guessedUrl,
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_database' => '',
            'db_username' => '',
            'db_password' => '',
            'admin_name' => '',
            'admin_email' => '',
            'admin_password' => '',
            'admin_password_confirmation' => '',
            'shipping_origin_state' => 'BA',
            'mail_host' => '',
            'mail_port' => '587',
            'mail_username' => '',
            'mail_password' => '',
            'mp_access_token' => '',
            'mp_webhook_secret' => '',
            'sample_data' => '',
        ];
    }

    /** Campos que nunca voltam preenchidos para a tela. */
    public const SECRETS = ['db_password', 'admin_password', 'admin_password_confirmation', 'mail_password', 'mp_access_token', 'mp_webhook_secret'];

    /**
     * @param array<string, mixed> $input $_POST
     * @return array<string, string>
     */
    public static function fromInput(array $input, string $guessedUrl): array
    {
        $data = [];
        foreach (self::defaults($guessedUrl) as $field => $default) {
            $value = $input[$field] ?? ($field === 'sample_data' ? '' : $default);
            $value = is_string($value) ? $value : '';
            // Senhas ficam como digitadas (espaços podem ser de propósito)
            $data[$field] = in_array($field, self::SECRETS, true) ? $value : trim($value);
        }
        $data['app_url'] = rtrim($data['app_url'], '/');
        $data['admin_email'] = mb_strtolower($data['admin_email']);
        $data['shipping_origin_state'] = strtoupper($data['shipping_origin_state']);
        $data['sample_data'] = $data['sample_data'] === '1' ? '1' : '';

        return $data;
    }

    /**
     * @param array<string, string> $data
     * @return array<string, string> campo => mensagem
     */
    public static function validate(array $data): array
    {
        $errors = [];
        $url = $data['app_url'];
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#', $url)) {
            $errors['app_url'] = 'Informe o endereço completo, começando com https:// (ex.: https://gnesting.com.br).';
        } elseif (Installer::isProductionUrl($url) && !str_starts_with($url, 'https://')) {
            $errors['app_url'] = 'Em produção a loja precisa de https://. Ative o certificado (cPanel → SSL/TLS Status) e use https://.';
        }

        if ($data['db_host'] === '') {
            $errors['db_host'] = 'Informe o servidor do banco (na hospedagem, normalmente "localhost").';
        }
        if (!ctype_digit($data['db_port']) || (int) $data['db_port'] < 1 || (int) $data['db_port'] > 65535) {
            $errors['db_port'] = 'Porta inválida (normalmente 3306).';
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $data['db_database']) !== 1) {
            $errors['db_database'] = 'Informe o nome do banco como aparece no cPanel (ex.: usuario_gnesting).';
        }
        if ($data['db_username'] === '') {
            $errors['db_username'] = 'Informe o usuário do banco.';
        }

        if (mb_strlen($data['admin_name']) < 2 || mb_strlen($data['admin_name']) > 100) {
            $errors['admin_name'] = 'Informe seu nome.';
        }
        if (filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['admin_email'] = 'Informe um e-mail válido.';
        }
        if (mb_strlen($data['admin_password']) < Installer::MIN_ADMIN_PASSWORD) {
            $errors['admin_password'] = 'A senha do painel precisa de pelo menos ' . Installer::MIN_ADMIN_PASSWORD . ' caracteres.';
        } elseif ($data['admin_password'] !== $data['admin_password_confirmation']) {
            $errors['admin_password_confirmation'] = 'As senhas não conferem.';
        }

        if (!in_array($data['shipping_origin_state'], ZipCode::states(), true)) {
            $errors['shipping_origin_state'] = 'Escolha a UF de onde os pedidos saem.';
        }

        if ($data['mail_host'] !== '') {
            if ($data['mail_username'] === '' || $data['mail_password'] === '') {
                $errors['mail_username'] = 'Com servidor SMTP, informe também o usuário (e-mail) e a senha.';
            }
            if (!ctype_digit($data['mail_port'])) {
                $errors['mail_port'] = 'Porta inválida (587 ou 465).';
            }
        }

        if (($data['mp_access_token'] === '') !== ($data['mp_webhook_secret'] === '')) {
            $errors['mp_webhook_secret'] = 'Informe o Access Token e a assinatura secreta do webhook juntos (ou deixe os dois para depois).';
        }

        return $errors;
    }
}
