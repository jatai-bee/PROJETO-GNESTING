<?php

declare(strict_types=1);

namespace GNesting\Services\Operations;

use GNesting\Core\Config;
use GNesting\Core\Database;
use GNesting\Core\Migrations\Migrator;

/**
 * Migrations pelo painel (Sistema → Atualizar banco de dados), para quem não tem Terminal.
 * Mesmo Migrator do database/migrate.php; seeds nunca rodam por aqui (são de desenvolvimento).
 */
final class SchemaUpdater
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
    ) {
    }

    /** @return list<string> migrations ainda não aplicadas */
    public function pending(): array
    {
        $status = $this->migrator()->status();

        return array_values(array_filter(array_keys($status),
            fn (string $name) => !$status[$name] && !str_starts_with($name, 'seed:')));
    }

    /** @return list<string> migrations aplicadas agora */
    public function apply(): array
    {
        return $this->migrator()->migrate();
    }

    private function migrator(): Migrator
    {
        $base = (string) $this->config->get('paths.base') . '/database';

        return new Migrator(
            $this->db->pdo(),
            (string) $this->config->get('paths.migrations', $base . '/migrations'),
            (string) $this->config->get('paths.seeds', $base . '/seeds'),
        );
    }
}
