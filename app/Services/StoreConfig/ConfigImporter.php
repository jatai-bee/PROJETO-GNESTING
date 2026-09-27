<?php

declare(strict_types=1);

namespace GNesting\Services\StoreConfig;

use GNesting\Controllers\Admin\CategoryController;
use GNesting\Controllers\Admin\MaterialController;
use GNesting\Core\Database;
use GNesting\Core\Validator;
use GNesting\Core\ValidationException;
use GNesting\Helpers\ZipCode;
use GNesting\Services\AuditService;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\CategoryService;
use GNesting\Services\MaterialService;
use GNesting\Services\SettingsService;
use GNesting\Services\Shipping\ShippingSettings;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Importa a configuração da loja de um YAML (Painel → Configurações → Importar).
 *
 * Regras:
 * - IMPORTAR NUNCA APAGA. Categorias casam pelo slug e materiais pelo código; o que não está no
 *   arquivo fica como está. Um arquivo incompleto não pode levar junto categorias com produtos.
 * - O arquivo inteiro é validado antes de gravar qualquer coisa, e a gravação é uma transação:
 *   ou entra tudo, ou nada. Os erros dizem onde estão ("categorias[2].slug").
 * - Mesmas regras e auditoria das telas: passa pelos services e pelas regras dos formulários.
 * - Chave desconhecida é erro (um "whatsap_numero" digitado errado não pode sumir em silêncio).
 * - Saldo de material nunca é alterado por aqui (tem razão próprio: Materiais → Movimentar saldo).
 */
final class ConfigImporter
{
    public const MAX_BYTES = 512 * 1024;

    private const STORE_KEYS = [
        'whatsapp_numero' => 'whatsapp.number',
        'whatsapp_mensagem' => 'whatsapp.default_message',
        'whatsapp_botao_flutuante' => 'whatsapp.floating_button',
        'email_contato' => 'store.contact_email',
        'faixa_avisos' => 'store.announcement',
    ];
    private const SHIPPING_KEYS = ['uf_origem', 'frete_gratis_acima', 'servico_frete_gratis', 'retirada', 'tabela'];
    private const CATEGORY_KEYS = ['nome', 'slug', 'descricao', 'ordem', 'ativa', 'meta_titulo', 'meta_descricao', 'subcategorias'];
    private const MATERIAL_KEYS = ['codigo', 'nome', 'espessura_mm', 'unidade', 'chapa_largura_mm', 'chapa_comprimento_mm', 'custo', 'estoque_minimo', 'ativo'];

    /** @var array<string, string> caminho => mensagem */
    private array $errors = [];

    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly ShippingSettings $shipping,
        private readonly CategoryService $categories,
        private readonly MaterialService $materials,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array<string, int> o que foi aplicado (rótulo => quantidade)
     * @throws ValidationException com os erros por caminho; nada é gravado
     */
    public function fromYaml(string $yaml): array
    {
        if (strlen($yaml) > self::MAX_BYTES) {
            throw new ValidationException(['arquivo' => 'Arquivo grande demais para uma configuração (máximo 512 KB).']);
        }
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new ValidationException(['arquivo' => 'O arquivo não é um YAML válido: ' . $e->getMessage()]);
        }
        if (!is_array($data) || $data === [] || array_is_list($data)) {
            throw new ValidationException(['arquivo' => 'O arquivo está vazio ou não tem o formato de uma configuração exportada.']);
        }

        $this->errors = [];
        foreach (array_diff(array_keys($data), ['loja', 'frete', 'categorias', 'materiais']) as $unknown) {
            $this->errors[(string) $unknown] = 'Seção desconhecida. Use loja, frete, categorias e materiais.';
        }
        $store = isset($data['loja']) ? $this->validateStore($data['loja']) : null;
        $shipping = isset($data['frete']) ? $this->validateShipping($data['frete']) : null;
        $categories = isset($data['categorias']) ? $this->validateCategories($data['categorias']) : [];
        $materials = isset($data['materiais']) ? $this->validateMaterials($data['materiais']) : [];
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        return $this->db->transaction(function () use ($store, $shipping, $categories, $materials): array {
            $summary = [];
            if ($store !== null) {
                $this->at('loja', fn () => $this->settings->save($store), [
                    'whatsapp_number' => 'whatsapp_numero', 'whatsapp_default_message' => 'whatsapp_mensagem',
                    'store_contact_email' => 'email_contato', 'store_announcement' => 'faixa_avisos',
                ]);
                $summary['configurações da loja'] = 1;
            }
            if ($shipping !== null) {
                $before = $this->shipping->effective();
                $this->shipping->save($shipping);
                $this->audit->recordChanges(AuditService::UPDATE, 'shipping', 0, $before, $shipping);
                $summary['tabela de frete'] = 1;
            }
            $summary += $this->applyCategories($categories);
            $summary += $this->applyMaterials($materials);
            $this->audit->record(AuditService::UPDATE, 'store_config', null, null, ['importado' => $summary]);

            return array_filter($summary);
        });
    }

    // ---- loja ---------------------------------------------------------------------------

    /** @return array<string, string>|null */
    private function validateStore(mixed $section): ?array
    {
        if (!$this->isMap($section, 'loja')) {
            return null;
        }
        $values = $this->settings->editable(); // o que não vier no arquivo fica como está
        // save() entende qualquer texto não vazio como "ligado"; editable() devolve '0' quando desligado
        $values['whatsapp.floating_button'] = $values['whatsapp.floating_button'] === '1' ? '1' : '';
        foreach ($section as $key => $value) {
            $setting = self::STORE_KEYS[$key] ?? null;
            if ($setting === null) {
                $this->errors["loja.{$key}"] = 'Chave desconhecida. Aceitas: ' . implode(', ', array_keys(self::STORE_KEYS)) . '.';
                continue;
            }
            if ($key === 'whatsapp_botao_flutuante') {
                $values[$setting] = $this->bool($value, "loja.{$key}") ? '1' : '';
                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                $this->errors["loja.{$key}"] = 'Deve ser um texto.';
                continue;
            }
            $values[$setting] = trim((string) $value);
        }

        return $values;
    }

    // ---- frete --------------------------------------------------------------------------

    /** @return array{origin_state: string, free_shipping_min_cents: int|null, free_shipping_service: string, pickup: array{enabled: bool, label: string, days: int}, table: array<string, array<string, array{0: int, 1: int, 2: int}>>}|null */
    private function validateShipping(mixed $section): ?array
    {
        if (!$this->isMap($section, 'frete')) {
            return null;
        }
        $current = $this->shipping->effective(); // o que não vier no arquivo fica como está
        $services = array_keys($this->shipping->services());
        foreach (array_diff(array_keys($section), self::SHIPPING_KEYS) as $unknown) {
            $this->errors["frete.{$unknown}"] = 'Chave desconhecida. Aceitas: ' . implode(', ', self::SHIPPING_KEYS) . '.';
        }

        if (array_key_exists('uf_origem', $section)) {
            $uf = strtoupper(trim((string) (is_scalar($section['uf_origem']) ? $section['uf_origem'] : '')));
            in_array($uf, ZipCode::states(), true)
                ? $current['origin_state'] = $uf
                : $this->errors['frete.uf_origem'] = 'UF inválida (ex.: BA).';
        }
        if (array_key_exists('frete_gratis_acima', $section)) {
            $current['free_shipping_min_cents'] = $section['frete_gratis_acima'] === null
                ? null : $this->cents($section['frete_gratis_acima'], 'frete.frete_gratis_acima');
        }
        if (array_key_exists('servico_frete_gratis', $section)) {
            $service = (string) (is_scalar($section['servico_frete_gratis']) ? $section['servico_frete_gratis'] : '');
            in_array($service, $services, true)
                ? $current['free_shipping_service'] = $service
                : $this->errors['frete.servico_frete_gratis'] = 'Serviço inválido. Use: ' . implode(', ', $services) . '.';
        }
        if (array_key_exists('retirada', $section) && $this->isMap($section['retirada'], 'frete.retirada')) {
            foreach ($section['retirada'] as $key => $value) {
                match ($key) {
                    'ativa' => $current['pickup']['enabled'] = $this->bool($value, 'frete.retirada.ativa'),
                    'rotulo' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 60
                        ? $current['pickup']['label'] = trim($value)
                        : $this->errors['frete.retirada.rotulo'] = 'Texto de 1 a 60 caracteres.',
                    'dias' => $current['pickup']['days'] = $this->int($value, 'frete.retirada.dias', 0, 90) ?? 0,
                    default => $this->errors["frete.retirada.{$key}"] = 'Chave desconhecida. Aceitas: ativa, rotulo, dias.',
                };
            }
        }
        if (array_key_exists('tabela', $section) && $this->isMap($section['tabela'], 'frete.tabela')) {
            foreach ($section['tabela'] as $band => $rates) {
                $path = "frete.tabela.{$band}";
                if (!in_array($band, ShippingSettings::BANDS, true)) {
                    $this->errors[$path] = 'Faixa desconhecida. Use: ' . implode(', ', ShippingSettings::BANDS) . '.';
                    continue;
                }
                if (!$this->isMap($rates, $path)) {
                    continue;
                }
                foreach ($rates as $service => $rate) {
                    $ratePath = "{$path}.{$service}";
                    if (!in_array($service, $services, true)) {
                        $this->errors[$ratePath] = 'Serviço desconhecido. Use: ' . implode(', ', $services) . '.';
                        continue;
                    }
                    if (!$this->isMap($rate, $ratePath)) {
                        continue;
                    }
                    foreach (array_diff(array_keys($rate), ['ate_1kg', 'por_kg_adicional', 'prazo_dias']) as $unknown) {
                        $this->errors["{$ratePath}.{$unknown}"] = 'Chave desconhecida. Aceitas: ate_1kg, por_kg_adicional, prazo_dias.';
                    }
                    [$base, $perKg, $days] = $current['table'][$band][$service] ?? [null, 0, null];
                    $base = array_key_exists('ate_1kg', $rate) ? $this->cents($rate['ate_1kg'], "{$ratePath}.ate_1kg") : $base;
                    $perKg = array_key_exists('por_kg_adicional', $rate) ? $this->cents($rate['por_kg_adicional'], "{$ratePath}.por_kg_adicional") : $perKg;
                    $days = array_key_exists('prazo_dias', $rate) ? $this->int($rate['prazo_dias'], "{$ratePath}.prazo_dias", 0, 90) : $days;
                    if ($base === null || $days === null) {
                        $this->errors[$ratePath] ??= 'Serviço novo nesta faixa: informe ate_1kg e prazo_dias.';
                        continue;
                    }
                    $current['table'][$band][$service] = [$base, (int) $perKg, $days];
                }
            }
        }

        return $current;
    }

    // ---- categorias ---------------------------------------------------------------------

    /** @return list<array{path: string, input: array<string, mixed>, children: list<array{path: string, input: array<string, mixed>}>}> */
    private function validateCategories(mixed $section): array
    {
        if (!$this->isList($section, 'categorias')) {
            return [];
        }
        $seen = [];
        $result = [];
        foreach ($section as $i => $item) {
            $path = "categorias[{$i}]";
            $category = $this->validateCategory($item, $path, $seen, true);
            if ($category === null) {
                continue;
            }
            $children = [];
            if (array_key_exists('subcategorias', $item) && $item['subcategorias'] !== null && $this->isList($item['subcategorias'], "{$path}.subcategorias")) {
                foreach ($item['subcategorias'] as $j => $child) {
                    $childPath = "{$path}.subcategorias[{$j}]";
                    $valid = $this->validateCategory($child, $childPath, $seen, false);
                    if ($valid !== null) {
                        $children[] = ['path' => $childPath, 'input' => $valid];
                    }
                }
            }
            $result[] = ['path' => $path, 'input' => $category, 'children' => $children];
        }

        return $result;
    }

    /**
     * @param array<string, string> $seen slugs já vistos no arquivo
     * @return array<string, mixed>|null campos informados, no formato do CategoryService
     */
    private function validateCategory(mixed $item, string $path, array &$seen, bool $topLevel): ?array
    {
        if (!$this->isMap($item, $path)) {
            return null;
        }
        $allowed = $topLevel ? self::CATEGORY_KEYS : array_diff(self::CATEGORY_KEYS, ['subcategorias']);
        foreach (array_diff(array_keys($item), $allowed) as $unknown) {
            $this->errors["{$path}.{$unknown}"] = $unknown === 'subcategorias'
                ? 'Só dois níveis: uma subcategoria não pode ter subcategorias.'
                : 'Chave desconhecida. Aceitas: ' . implode(', ', $allowed) . '.';
        }

        $slug = is_string($item['slug'] ?? null) ? trim($item['slug']) : '';
        if ($slug === '') {
            $this->errors["{$path}.slug"] = 'Informe o slug: é por ele que a categoria é encontrada.';

            return null;
        }
        if (isset($seen[$slug])) {
            $this->errors["{$path}.slug"] = "Slug repetido no arquivo (também em {$seen[$slug]}).";

            return null;
        }
        $seen[$slug] = $path;

        $map = ['nome' => 'name', 'descricao' => 'description', 'ordem' => 'sort_order', 'meta_titulo' => 'meta_title', 'meta_descricao' => 'meta_description'];
        $input = ['slug' => $slug];
        $form = ['slug' => $slug];
        foreach ($map as $yamlKey => $field) {
            if (array_key_exists($yamlKey, $item)) {
                $form[$field] = $this->text($item[$yamlKey], "{$path}.{$yamlKey}");
                $input[$field] = $field === 'sort_order' ? (int) $form[$field] : ($form[$field] === '' ? null : $form[$field]);
            }
        }
        if (array_key_exists('ativa', $item)) {
            $input['is_active'] = $this->bool($item['ativa'], "{$path}.ativa");
        }

        // Mesmas regras do formulário de categorias; "nome" só é obrigatório para categoria nova
        $rules = array_intersect_key(CategoryController::RULES, $form);
        foreach (Validator::errors($form, $rules, CategoryController::LABELS) as $field => $message) {
            $this->errors["{$path}." . (array_search($field, $map, true) ?: $field)] = $message;
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $this->errors["{$path}.slug"] = 'Use só letras minúsculas sem acento, números e hífens (ex.: relogios-de-parede).';
        }
        if (!isset($input['name']) && $this->categoryBySlug($slug) === null) {
            $this->errors["{$path}.nome"] = 'Categoria nova: informe o nome.';
        }

        return $input;
    }

    /**
     * @param list<array{path: string, input: array<string, mixed>, children: list<array{path: string, input: array<string, mixed>}>}> $categories
     * @return array<string, int>
     */
    private function applyCategories(array $categories): array
    {
        $count = ['categorias novas' => 0, 'categorias atualizadas' => 0];
        foreach ($categories as $category) {
            $parentId = $this->saveCategory($category['input'], null, $category['path'], $count);
            foreach ($category['children'] as $child) {
                $this->saveCategory($child['input'], $parentId, $child['path'], $count);
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, int> $count
     */
    private function saveCategory(array $input, ?int $parentId, string $path, array &$count): int
    {
        $current = $this->categoryBySlug((string) $input['slug']);
        $data = $input + [
            'name' => $current['name'] ?? '',
            'description' => $current['description'] ?? null,
            'sort_order' => (int) ($current['sort_order'] ?? 0),
            'is_active' => (bool) ($current['is_active'] ?? true),
            'meta_title' => $current['meta_title'] ?? null,
            'meta_description' => $current['meta_description'] ?? null,
        ];
        $data['parent_id'] = $parentId;

        return $this->at($path, function () use ($current, $data, &$count): int {
            if ($current === null) {
                $count['categorias novas']++;

                return $this->categories->create($data);
            }
            $count['categorias atualizadas']++;
            $this->categories->update((int) $current['id'], $data);

            return (int) $current['id'];
        });
    }

    /** @return array<string, mixed>|null */
    private function categoryBySlug(string $slug): ?array
    {
        $statement = $this->db->pdo()->prepare('SELECT * FROM categories WHERE slug = :slug AND deleted_at IS NULL');
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    // ---- materiais ----------------------------------------------------------------------

    /** @return list<array{path: string, input: array<string, mixed>}> */
    private function validateMaterials(mixed $section): array
    {
        if (!$this->isList($section, 'materiais')) {
            return [];
        }
        $units = array_flip(ConfigExporter::UNITS); // chapa → sheet
        $seen = [];
        $result = [];
        foreach ($section as $i => $item) {
            $path = "materiais[{$i}]";
            if (!$this->isMap($item, $path)) {
                continue;
            }
            foreach (array_diff(array_keys($item), self::MATERIAL_KEYS) as $unknown) {
                $this->errors["{$path}.{$unknown}"] = 'Chave desconhecida. Aceitas: ' . implode(', ', self::MATERIAL_KEYS) . '.';
            }
            $code = strtoupper($this->text($item['codigo'] ?? '', "{$path}.codigo"));
            if ($code === '') {
                $this->errors["{$path}.codigo"] = 'Informe o código: é por ele que o material é encontrado.';
                continue;
            }
            if (isset($seen[$code])) {
                $this->errors["{$path}.codigo"] = "Código repetido no arquivo (também em {$seen[$code]}).";
                continue;
            }
            $seen[$code] = $path;
            $current = $this->materialByCode($code);

            // Formato do formulário de materiais, para passar pelas mesmas regras
            $form = [
                'code' => $code,
                'name' => array_key_exists('nome', $item) ? $this->text($item['nome'], "{$path}.nome") : (string) ($current['name'] ?? ''),
                'thickness_mm' => array_key_exists('espessura_mm', $item) ? $this->text($item['espessura_mm'], "{$path}.espessura_mm") : (string) ($current['thickness_mm'] ?? ''),
                'sheet_width_mm' => array_key_exists('chapa_largura_mm', $item) ? $this->text($item['chapa_largura_mm'], "{$path}.chapa_largura_mm") : (string) ($current['sheet_width_mm'] ?? ''),
                'sheet_length_mm' => array_key_exists('chapa_comprimento_mm', $item) ? $this->text($item['chapa_comprimento_mm'], "{$path}.chapa_comprimento_mm") : (string) ($current['sheet_length_mm'] ?? ''),
                'unit' => array_key_exists('unidade', $item) ? ($units[$this->text($item['unidade'], "{$path}.unidade")] ?? '?') : (string) ($current['unit'] ?? 'sheet'),
                'cost' => array_key_exists('custo', $item) ? $this->text($item['custo'], "{$path}.custo") : ($current === null || $current['cost_cents'] === null ? '' : money_input((int) $current['cost_cents'])),
                'reorder_level' => array_key_exists('estoque_minimo', $item) ? $this->text($item['estoque_minimo'], "{$path}.estoque_minimo") : (string) ($current['reorder_level'] ?? ''),
            ];
            $map = ['code' => 'codigo', 'name' => 'nome', 'thickness_mm' => 'espessura_mm', 'sheet_width_mm' => 'chapa_largura_mm',
                'sheet_length_mm' => 'chapa_comprimento_mm', 'unit' => 'unidade', 'cost' => 'custo', 'reorder_level' => 'estoque_minimo'];
            $rules = array_diff_key(MaterialController::rules(), ['stock_qty' => true]);
            foreach (Validator::errors($form, $rules, MaterialController::LABELS) as $field => $message) {
                $this->errors["{$path}." . ($map[$field] ?? $field)] = $field === 'unit' ? 'Unidade inválida. Use: chapa, m2 ou unidade.' : $message;
            }

            $result[] = ['path' => $path, 'input' => [
                'code' => $code,
                'name' => $form['name'],
                'thickness_mm' => parse_decimal($form['thickness_mm']),
                'sheet_width_mm' => $form['sheet_width_mm'] === '' ? null : (int) $form['sheet_width_mm'],
                'sheet_length_mm' => $form['sheet_length_mm'] === '' ? null : (int) $form['sheet_length_mm'],
                'unit' => $form['unit'],
                'cost_cents' => $form['cost'] === '' ? null : parse_money($form['cost']),
                'stock_qty' => (string) ($current['stock_qty'] ?? '0.00'), // saldo: nunca pelo arquivo
                'reorder_level' => parse_decimal($form['reorder_level']),
                'is_active' => array_key_exists('ativo', $item) ? $this->bool($item['ativo'], "{$path}.ativo") : (bool) ($current['is_active'] ?? true),
                'id' => $current === null ? null : (int) $current['id'],
            ]];
        }

        return $result;
    }

    /**
     * @param list<array{path: string, input: array<string, mixed>}> $materials
     * @return array<string, int>
     */
    private function applyMaterials(array $materials): array
    {
        $count = ['materiais novos' => 0, 'materiais atualizados' => 0];
        foreach ($materials as $material) {
            $input = $material['input'];
            $id = $input['id'];
            unset($input['id']);
            $this->at($material['path'], function () use ($id, $input, &$count): void {
                if ($id === null) {
                    $this->materials->create($input);
                    $count['materiais novos']++;
                } else {
                    $this->materials->update($id, $input);
                    $count['materiais atualizados']++;
                }
            });
        }

        return $count;
    }

    /** @return array<string, mixed>|null */
    private function materialByCode(string $code): ?array
    {
        $statement = $this->db->pdo()->prepare('SELECT * FROM materials WHERE code = :code');
        $statement->execute(['code' => $code]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    // ---- utilidades ---------------------------------------------------------------------

    /**
     * Executa uma gravação e, se o service recusar, devolve o erro com o caminho no arquivo.
     *
     * @template T
     * @param callable(): T $callback
     * @param array<string, string> $fieldMap campo do formulário => chave no YAML
     * @return T
     */
    private function at(string $path, callable $callback, array $fieldMap = []): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $field => $message) {
                $errors[isset($fieldMap[$field]) ? "{$path}.{$fieldMap[$field]}" : "{$path} ({$field})"] = $message;
            }
            throw new ValidationException($errors);
        } catch (BusinessRuleException $e) {
            throw new ValidationException([$path => $e->getMessage()]);
        }
    }

    /** @phpstan-assert-if-true array<string, mixed> $value */
    private function isMap(mixed $value, string $path): bool
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->errors[$path] = 'Formato inválido: esperado um bloco de chaves (chave: valor).';

            return false;
        }

        return true;
    }

    /** @phpstan-assert-if-true list<mixed> $value */
    private function isList(mixed $value, string $path): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->errors[$path] = 'Formato inválido: esperada uma lista (itens começando com "- ").';

            return false;
        }

        return true;
    }

    /** Texto no formato de formulário (números sem notação científica, decimais com ponto). */
    private function text(mixed $value, string $path): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.2f', $value), '0'), '.'),
            is_string($value) => trim($value),
            default => (function () use ($path): string {
                $this->errors[$path] = 'Deve ser um valor simples (texto ou número).';

                return '';
            })(),
        };
    }

    private function bool(mixed $value, string $path): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $this->errors[$path] = 'Use true ou false.';

        return false;
    }

    private function int(mixed $value, string $path, int $min, int $max): ?int
    {
        if (is_int($value) && $value >= $min && $value <= $max) {
            return $value;
        }
        $this->errors[$path] = "Número inteiro de {$min} a {$max}.";

        return null;
    }

    /** Reais (19.90, 19, "19,90") → centavos; null e erro se inválido ou negativo. */
    private function cents(mixed $value, string $path): ?int
    {
        $cents = is_int($value) || is_float($value) || is_string($value) ? parse_money($this->text($value, $path)) : null;
        if ($cents === null || $cents > 99_999_99) {
            $this->errors[$path] = 'Valor em reais inválido (ex.: 19.90).';

            return null;
        }

        return $cents;
    }
}
