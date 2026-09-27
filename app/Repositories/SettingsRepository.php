<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/** Configurações editáveis no painel (chave/valor, valores não sensíveis). */
final class SettingsRepository extends Repository
{
    /** @return array<string, string> */
    public function all(): array
    {
        $settings = [];
        foreach ($this->fetchAll('SELECT setting_key, setting_value FROM settings') as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $settings;
    }

    public function get(string $key): ?string
    {
        $value = $this->fetchValue('SELECT setting_value FROM settings WHERE setting_key = :key', ['key' => $key]);

        return $value === false || $value === null ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $this->execute(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = :value2',
            ['key' => $key, 'value' => $value, 'value2' => $value]
        );
    }
}
