<?php

declare(strict_types=1);

namespace GNesting\Services\StoreConfig;

use GNesting\Core\Database;
use GNesting\Helpers\BrazilianDocument;
use GNesting\Services\SettingsService;
use GNesting\Services\Shipping\ShippingSettings;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuração da loja em YAML (Painel → Configurações → Exportar).
 *
 * Serve para, sem Terminal: guardar o que foi ajustado no painel; levar a configuração de uma
 * instalação para outra (do computador para a hospedagem); e editar a tabela de frete num editor
 * de texto. Um YAML se lê; um dump SQL, não.
 *
 * Só CONFIGURAÇÃO: produtos, pedidos e clientes ficam de fora de propósito (são dados de operação;
 * o lugar deles é o backup do banco, em Sistema).
 */
final class ConfigExporter
{
    /** Unidade do banco → como aparece no YAML */
    public const UNITS = ['sheet' => 'chapa', 'm2' => 'm2', 'unit' => 'unidade'];

    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly ShippingSettings $shipping,
    ) {
    }

    public function toYaml(): string
    {
        $header = "# Configuração da loja G-Nesting\n"
            . '# Exportado em ' . format_datetime(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i') . "\n"
            . "#\n"
            . "# Para aplicar: Painel → Configurações → Importar configuração. Importar nunca apaga:\n"
            . "# categorias e materiais são casados pelo slug/código, e o que não estiver aqui fica como está.\n"
            . "# Valores em reais com ponto decimal (19.90). Saldo de materiais não entra: use Materiais → Movimentar saldo.\n"
            . "#\n"
            . "# frete.tabela: por faixa (local = mesma UF da origem; N, NE, CO, SE, S = região de destino) e por serviço\n"
            . "# (economico, expresso): ate_1kg = preço até 1 kg; por_kg_adicional = acréscimo por kg acima de 1 kg.\n\n";

        // Profundidade 6: menos que isso e as listas virariam JSON numa linha só, ilegível para editar
        return $header . Yaml::dump($this->toArray(), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'loja' => $this->store(),
            'frete' => $this->shippingSection(),
            'categorias' => $this->categories(null),
            'materiais' => $this->materials(),
        ];
    }

    /** @return array<string, mixed> */
    private function store(): array
    {
        $s = $this->settings->editable();

        return [
            'whatsapp_numero' => BrazilianDocument::formatPhone($s['whatsapp.number'] ?: null),
            'whatsapp_mensagem' => $s['whatsapp.default_message'],
            'whatsapp_botao_flutuante' => $s['whatsapp.floating_button'] === '1',
            'email_contato' => $s['store.contact_email'],
            'faixa_avisos' => $s['store.announcement'],
        ];
    }

    /** @return array<string, mixed> */
    private function shippingSection(): array
    {
        $s = $this->shipping->effective();
        $table = [];
        foreach (ShippingSettings::BANDS as $band) {
            foreach ($s['table'][$band] ?? [] as $service => [$base, $perKg, $days]) {
                $table[$band][$service] = ['ate_1kg' => self::reais($base), 'por_kg_adicional' => self::reais($perKg), 'prazo_dias' => (int) $days];
            }
        }

        return [
            'uf_origem' => $s['origin_state'],
            'frete_gratis_acima' => $s['free_shipping_min_cents'] === null ? null : self::reais($s['free_shipping_min_cents']),
            'servico_frete_gratis' => $s['free_shipping_service'],
            'retirada' => ['ativa' => $s['pickup']['enabled'], 'rotulo' => $s['pickup']['label'], 'dias' => $s['pickup']['days']],
            'tabela' => $table,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function categories(?int $parentId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, name, slug, description, sort_order, is_active, meta_title, meta_description
               FROM categories
              WHERE deleted_at IS NULL AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent') . '
              ORDER BY sort_order, name'
        );
        $statement->execute($parentId === null ? [] : ['parent' => $parentId]);

        $list = [];
        foreach ($statement->fetchAll() as $row) {
            $category = [
                'nome' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'descricao' => $row['description'],
                'ordem' => (int) $row['sort_order'],
                'ativa' => (bool) $row['is_active'],
                'meta_titulo' => $row['meta_title'],
                'meta_descricao' => $row['meta_description'],
            ];
            if ($parentId === null) {
                $category['subcategorias'] = $this->categories((int) $row['id']);
            }
            $list[] = $category;
        }

        return $list;
    }

    /** @return list<array<string, mixed>> */
    private function materials(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT code, name, thickness_mm, unit, sheet_width_mm, sheet_length_mm, cost_cents, reorder_level, is_active
               FROM materials ORDER BY code'
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'codigo' => (string) $row['code'],
            'nome' => (string) $row['name'],
            'espessura_mm' => (float) $row['thickness_mm'],
            'unidade' => self::UNITS[(string) $row['unit']] ?? (string) $row['unit'],
            'chapa_largura_mm' => $row['sheet_width_mm'] === null ? null : (int) $row['sheet_width_mm'],
            'chapa_comprimento_mm' => $row['sheet_length_mm'] === null ? null : (int) $row['sheet_length_mm'],
            'custo' => $row['cost_cents'] === null ? null : self::reais((int) $row['cost_cents']),
            'estoque_minimo' => $row['reorder_level'] === null ? null : (float) $row['reorder_level'],
            'ativo' => (bool) $row['is_active'],
        ], $rows);
    }

    private static function reais(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
