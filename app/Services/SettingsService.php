<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Database;
use GNesting\Core\ValidationException;
use GNesting\Helpers\BrazilianDocument;
use GNesting\Repositories\SettingsRepository;

/**
 * Configurações da loja editáveis pelo proprietário (/admin/configuracoes).
 * Somente valores públicos/operacionais — segredos ficam no .env.
 */
final class SettingsService
{
    /** Chaves editáveis e valor padrão. */
    public const DEFAULTS = [
        'whatsapp.number' => '',
        'whatsapp.default_message' => 'Olá! Tenho uma dúvida sobre um produto da G-Nesting.',
        'whatsapp.floating_button' => '1',
        'store.contact_email' => 'contato@gnesting.com.br',
        'store.announcement' => '',
    ];

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Database $db,
        private readonly SettingsRepository $settings,
        private readonly AuditService $audit,
    ) {
    }

    public function get(string $key): string
    {
        $this->cache ??= $this->settings->all();

        return $this->cache[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    /** @return array<string, string> */
    public function editable(): array
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * @param array<string, string> $input
     * @throws ValidationException
     */
    public function save(array $input): void
    {
        $errors = [];
        $number = trim($input['whatsapp.number'] ?? '');
        $phone = $number === '' ? '' : BrazilianDocument::phone($number);
        if ($phone === null) {
            $errors['whatsapp_number'] = 'Informe o WhatsApp com DDD, ex.: (71) 99999-8888.';
        }
        $email = trim($input['store.contact_email'] ?? '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['store_contact_email'] = 'E-mail inválido.';
        }
        if (mb_strlen($input['whatsapp.default_message'] ?? '') > 300) {
            $errors['whatsapp_default_message'] = 'Até 300 caracteres.';
        }
        if (mb_strlen($input['store.announcement'] ?? '') > 140) {
            $errors['store_announcement'] = 'Até 140 caracteres (cabe numa linha no celular).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $values = [
            'whatsapp.number' => (string) $phone,
            'whatsapp.default_message' => trim($input['whatsapp.default_message'] ?? ''),
            'whatsapp.floating_button' => ($input['whatsapp.floating_button'] ?? '') !== '' ? '1' : '0',
            'store.contact_email' => $email,
            'store.announcement' => trim($input['store.announcement'] ?? ''),
        ];

        $this->db->transaction(function () use ($values): void {
            $before = $this->editable();
            foreach ($values as $key => $value) {
                $this->settings->set($key, $value);
            }
            $this->cache = null;
            $this->audit->recordChanges(AuditService::UPDATE, 'settings', 0, $before, $values);
        });
    }
}
