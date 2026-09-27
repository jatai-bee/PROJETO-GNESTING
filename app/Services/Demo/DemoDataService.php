<?php

declare(strict_types=1);

namespace GNesting\Services\Demo;

use DateTimeImmutable;
use DateTimeZone;
use GNesting\Core\CartContext;
use GNesting\Core\Config;
use GNesting\Core\Container;
use GNesting\Core\Database;
use GNesting\Core\UploadedFile;
use GNesting\Enums\OrderStatus;
use GNesting\Enums\UserType;
use GNesting\Repositories\AddressRepository;
use GNesting\Repositories\CustomerRepository;
use GNesting\Repositories\ProductImageRepository;
use GNesting\Repositories\UserRepository;
use GNesting\Services\Auth\PasswordHasher;
use GNesting\Services\CartService;
use GNesting\Services\CategoryService;
use GNesting\Services\CheckoutService;
use GNesting\Services\ImageProcessor;
use GNesting\Services\Mail\LogMailer;
use GNesting\Services\Mail\Mailer;
use GNesting\Services\MaterialService;
use GNesting\Services\OrderService;
use GNesting\Services\OrderStatusService;
use GNesting\Services\Production\ProductionFlow;
use GNesting\Services\Production\ProductionService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Carrega (e remove) a loja de demonstração: 7 categorias principais, 24 subcategorias, 34 produtos
 * com ilustrações, matéria-prima, 16 clientes e 29 pedidos.
 *
 * Os pedidos passam pelos MESMOS services da loja (carrinho → checkout → pagamento → fila de
 * produção → expedição), então aparecem de forma coerente em produção, estoque de chapas,
 * expedição, clientes e relatórios. Depois, as datas são espalhadas pelos últimos ~75 dias.
 *
 * Tudo que é criado fica em demo_records, para "Remover dados de demonstração" apagar exatamente
 * isso (docs/19). E-mails ficam num log temporário: nenhum cliente fictício recebe mensagem.
 */
final class DemoDataService
{
    public const CUSTOMER_PASSWORD = 'cliente-demo-123';

    private const STAGE_TARGET = [
        'cnc' => 'cnc', 'lixamento' => 'sanding', 'pintura' => 'painting', 'cq' => 'quality', 'embalagem' => 'packaging',
        'pronto' => 'done', 'enviado' => 'done', 'entregue' => 'done',
    ];

    private PDO $pdo;
    /** @var callable(string): void */
    private $log;

    public function __construct(
        private readonly Container $container,
        private readonly Database $db,
        private readonly Config $config,
    ) {
        $this->pdo = $db->pdo();
    }

    public function isInstalled(): bool
    {
        return $this->tableExists() && (int) $this->pdo->query('SELECT COUNT(*) FROM demo_records')->fetchColumn() > 0;
    }

    /**
     * @param callable(string): void|null $log
     * @return array<string, int> quantidades criadas
     */
    public function install(?callable $log = null): array
    {
        $this->log = $log ?? static function (string $line): void {
        };
        if (!$this->tableExists()) {
            throw new RuntimeException('Atualize o banco de dados antes (Sistema → Atualizar banco de dados).');
        }
        if ($this->isInstalled()) {
            throw new RuntimeException('Os dados de demonstração já estão instalados.');
        }
        // Nenhum e-mail real para clientes fictícios
        $this->container->instance(Mailer::class, new LogMailer(sys_get_temp_dir() . '/gnesting-demo-mail'));
        @set_time_limit(600);

        $materials = $this->materials();
        // Estoque que a loja já tinha: os pedidos fictícios não podem consumi-lo
        $before = [];
        foreach ($materials as $id) {
            if (!$this->isDemo('materials', $id)) {
                $before[$id] = (string) $this->value('SELECT stock_qty FROM materials WHERE id = ?', [$id]);
            }
        }
        $categories = $this->categories();
        $products = $this->products($categories, $materials);
        $this->coupons();
        $customers = $this->customers();
        $orders = $this->orders($products, $customers);
        $this->settleMaterials($materials, $before);

        return [
            'categorias' => count($categories),
            'produtos' => count($products),
            'matérias-primas' => count($materials),
            'clientes' => count($customers),
            'pedidos' => $orders,
        ];
    }

    // ---- matéria-prima -------------------------------------------------------------------

    /** @return array<string, int> código => id */
    private function materials(): array
    {
        $service = $this->container->get(MaterialService::class);
        $ids = [];
        foreach (DemoCatalog::materials() as $code => [$name, $thickness, $unit, $width, $length, $cost, $stock, $minimum]) {
            $existing = $this->value('SELECT id FROM materials WHERE code = ?', [$code]);
            if ($existing !== null) {
                $ids[$code] = (int) $existing;
                continue;
            }
            $id = $service->create([
                'code' => $code, 'name' => $name, 'thickness_mm' => $thickness, 'sheet_width_mm' => $width, 'sheet_length_mm' => $length,
                'unit' => $unit, 'cost_cents' => $cost, 'stock_qty' => '0.00', 'reorder_level' => $minimum, 'is_active' => true,
            ]);
            if ((float) $stock > 0) {
                $service->move($id, $stock, 'Estoque inicial (compra NF ' . (1000 + $id) . ')', null);
            }
            $this->record('materials', $id);
            $ids[$code] = $id;
        }
        ($this->log)(count($ids) . ' matérias-primas prontas.');

        return $ids;
    }

    /**
     * Depois dos pedidos: devolve o estoque das matérias-primas que já existiam na loja (e apaga o consumo
     * fictício delas) e deixa as da demonstração no nível planejado em DemoCatalog, com uma compra ou um
     * acerto de inventário registrado. Assim os alertas mostram só o que a demonstração quer mostrar.
     *
     * @param array<string, int>    $materials código => id
     * @param array<int, string>    $before    id => estoque antes (só as que já existiam)
     */
    private function settleMaterials(array $materials, array $before): void
    {
        $service = $this->container->get(MaterialService::class);
        foreach ($before as $id => $stock) {
            $this->pdo->prepare(
                "DELETE mm FROM material_movements mm
                   JOIN production_jobs j ON mm.reference_type = 'production_job' AND mm.reference_id = j.id
                   JOIN demo_records d ON d.entity = 'orders' AND d.entity_id = j.order_id
                  WHERE mm.material_id = ?"
            )->execute([$id]);
            $this->pdo->prepare('UPDATE materials SET stock_qty = ? WHERE id = ?')->execute([$stock, $id]);
        }
        foreach (DemoCatalog::materials() as $code => $material) {
            $id = $materials[$code] ?? null;
            if ($id === null || isset($before[$id])) {
                continue;
            }
            $diff = round((float) $material[6] - (float) $this->value('SELECT stock_qty FROM materials WHERE id = ?', [$id]), 2);
            if (abs($diff) >= 0.01) {
                $service->move($id, number_format($diff, 2, '.', ''), $diff > 0 ? 'Compra NF ' . (2000 + $id) : 'Acerto de inventário', null);
            }
        }
    }

    private function isDemo(string $entity, int $id): bool
    {
        return $this->value('SELECT 1 FROM demo_records WHERE entity = ? AND entity_id = ?', [$entity, $id]) !== null;
    }

    // ---- categorias ----------------------------------------------------------------------

    /** @return array<string, int> slug => id (principais e subcategorias) */
    private function categories(): array
    {
        $service = $this->container->get(CategoryService::class);
        $ids = [];
        $order = 10;
        foreach (DemoCatalog::categories() as $slug => $main) {
            $ids[$slug] = $this->category($service, $slug, $main['name'], $main['description'], null, $order);
            $order += 10;
            $childOrder = 10;
            foreach ($main['children'] as $childSlug => $childName) {
                $ids[$childSlug] = $this->category($service, $childSlug, $childName, null, $ids[$slug], $childOrder);
                $childOrder += 10;
            }
        }
        ($this->log)(count($ids) . ' categorias e subcategorias prontas.');

        return $ids;
    }

    private function category(CategoryService $service, string $slug, string $name, ?string $description, ?int $parentId, int $order): int
    {
        $existing = $this->value('SELECT id FROM categories WHERE slug = ? AND deleted_at IS NULL', [$slug]);
        if ($existing !== null) {
            return (int) $existing;
        }
        $id = $service->create([
            'name' => $name, 'slug' => $slug, 'parent_id' => $parentId, 'description' => $description, 'sort_order' => $order,
            'is_active' => true, 'meta_title' => null, 'meta_description' => null,
        ]);
        $this->record('categories', $id);

        return $id;
    }

    // ---- produtos ------------------------------------------------------------------------

    /**
     * @param array<string, int> $categories
     * @param array<string, int> $materials
     * @return array<string, array{id: int, variants: array<string, int>, rules: array<string, array{id: int, values: array<string, int>}>}>
     */
    private function products(array $categories, array $materials): array
    {
        $illustrator = new ProductIllustrator();
        $images = $this->container->get(ImageProcessor::class);
        $imageRepo = $this->container->get(ProductImageRepository::class);
        $created = [];
        $tmp = sys_get_temp_dir() . '/gnesting-demo-img-' . bin2hex(random_bytes(3));
        @mkdir($tmp, 0777, true);

        foreach (DemoCatalog::products() as $n => $p) {
            if ($this->value('SELECT id FROM products WHERE slug = ?', [$p['slug']]) !== null) {
                continue;
            }
            $this->pdo->beginTransaction();
            try {
                $productId = $this->insert('products', [
                    'category_id' => $categories[$p['sub']], 'name' => $p['name'], 'slug' => $p['slug'],
                    'short_description' => $p['short'], 'description' => $p['description'], 'highlights' => implode("\n", $p['highlights']),
                    'keywords' => $p['keywords'], 'care_instructions' => $p['care'], 'assembly_info' => $p['assembly'],
                    'production_lead_days' => $p['lead'], 'dispatch_days' => $p['dispatch'],
                    'personalization_enabled' => $p['pers'] === [] ? 0 : 1, 'is_active' => $p['active'] ? 1 : 0,
                    'is_featured' => $p['featured'] ? 1 : 0, 'is_new' => $p['new'] ? 1 : 0,
                    'meta_title' => mb_substr($p['name'] . ' | G-Nesting', 0, 70), 'meta_description' => mb_substr($p['short'], 0, 160),
                    'published_at' => $p['active'] ? gmdate('Y-m-d H:i:s', time() - (40 - $n) * 86400) : null,
                ]);
                $variants = $this->variants($productId, $p, $materials);
                $rules = $this->personalization($productId, $p['pers']);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
            $this->record('products', $productId);

            // Ilustrações: principal (capa) e detalhe
            foreach (['principal' => 'Vista frontal', 'detalhe' => 'Detalhe do acabamento'] as $view => $alt) {
                $file = $tmp . "/{$p['slug']}-{$view}.png";
                $illustrator->render($p['kind'], $p['finish'], $file, $view, $n);
                $path = $images->store(new UploadedFile(basename($file), $file, UPLOAD_ERR_OK, (int) filesize($file), false), 'products/' . $productId);
                $imageRepo->create($productId, $path, $p['name'] . ' — ' . mb_strtolower($alt), $view === 'principal');
                @unlink($file);
            }
            $created[$p['slug']] = ['id' => $productId, 'variants' => $variants, 'rules' => $rules];
            if (($n + 1) % 8 === 0) {
                ($this->log)(($n + 1) . ' produtos criados…');
            }
        }
        @rmdir($tmp);
        $this->categoryImages();
        ($this->log)(count($created) . ' produtos com fotos, variações, personalização e ficha de produção.');

        return $created;
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, int> $materials
     * @return array<string, int> nome da variação ('' = única) => id
     */
    private function variants(int $productId, array $p, array $materials): array
    {
        // Combinações das opções (produto cartesiano), cada uma com SKU e preço próprios
        $combos = [['label' => [], 'delta' => 0, 'suffix' => [], 'values' => []]];
        $optionOrder = 10;
        foreach ($p['variants'] as $optionName => $values) {
            $optionId = $this->insert('product_options', ['product_id' => $productId, 'name' => $optionName, 'sort_order' => $optionOrder]);
            $optionOrder += 10;
            $next = [];
            $valueOrder = 10;
            $valueIds = [];
            foreach ($values as $value => [$delta]) {
                $valueIds[$value] = $this->insert('product_option_values', ['option_id' => $optionId, 'value' => $value, 'sort_order' => $valueOrder]);
                $valueOrder += 10;
            }
            foreach ($combos as $combo) {
                foreach ($values as $value => [$delta]) {
                    $next[] = [
                        'label' => [...$combo['label'], $value],
                        'delta' => $combo['delta'] + $delta,
                        'suffix' => [...$combo['suffix'], self::skuSuffix((string) $value)],
                        'values' => [...$combo['values'], $valueIds[$value]],
                    ];
                }
            }
            $combos = $next;
        }

        [$w, $h, $d] = $p['dims'];
        $ids = [];
        foreach ($combos as $i => $combo) {
            $label = implode(' / ', $combo['label']);
            $sku = $p['sku'] . ($combo['suffix'] === [] ? '' : '-' . implode('-', $combo['suffix']));
            $variantId = $this->insert('product_variants', [
                'product_id' => $productId, 'sku' => $sku, 'name' => $label === '' ? null : $label,
                'price_cents' => $p['price'] + $combo['delta'],
                'compare_at_price_cents' => $p['compare'] === null ? null : $p['compare'] + $combo['delta'],
                'cost_cents' => $p['cost'] + (int) ($combo['delta'] * 0.35),
                'material_label' => $p['material'], 'finish_label' => $combo['label'] === [] ? $p['finish_label'] : end($combo['label']),
                'width_mm' => $w, 'height_mm' => $h, 'depth_mm' => $d, 'weight_g' => $p['weight'],
                'package_width_mm' => $w + 40, 'package_height_mm' => $h + 40, 'package_length_mm' => $d + 40, 'package_weight_g' => $p['weight'] + 250,
                'is_default' => $i === 0 ? 1 : 0, 'is_active' => 1, 'sort_order' => ($i + 1) * 10,
            ]);
            foreach ($combo['values'] as $valueId) {
                $this->insert('variant_option_values', ['variant_id' => $variantId, 'option_value_id' => $valueId]);
            }
            $stock = $p['stock'];
            $this->insert('inventory', [
                'variant_id' => $variantId, 'stock_mode' => $stock === null ? 'made_to_order' : 'stock',
                'quantity_on_hand' => (int) ($stock ?? 0), 'quantity_reserved' => 0, 'reorder_level' => $stock === null ? null : 5,
            ]);
            if ($stock !== null && $stock > 0) {
                $this->insert('inventory_movements', ['variant_id' => $variantId, 'type' => 'in', 'quantity' => $stock, 'reason' => 'Produção para estoque (lote inicial)']);
            }
            $this->spec($variantId, $sku, $p, $materials);
            $ids[$label] = $variantId;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, int> $materials
     */
    private function spec(int $variantId, string $sku, array $p, array $materials): void
    {
        [$materialCode, $perSheet, $minutes] = $p['spec'];
        [$w, $h] = $p['dims'];
        $thickness = $this->value('SELECT thickness_mm FROM materials WHERE id = ?', [$materials[$materialCode]]);
        $specId = $this->insert('production_specs', [
            'variant_id' => $variantId, 'material_id' => $materials[$materialCode], 'thickness_mm' => (float) $thickness > 0 ? $thickness : null,
            'cut_width_mm' => $w, 'cut_height_mm' => $h, 'pieces_per_sheet' => $perSheet, 'sheet_yield_percent' => '82.00',
            'cnc_program_ref' => 'CNC-' . $sku . '-v1',
            'finish_notes' => 'Lixar faces e bordas (grão 220). ' . (isset($minutes['painting']) ? 'Acabamento: ' . mb_strtolower((string) $p['finish_label']) . '.' : ''),
            'internal_notes' => $p['pers'] === [] ? null : 'Conferir a personalização do pedido antes de gravar.',
        ]);
        $labels = ['cnc' => 'Recorte e gravação', 'sanding' => 'Lixamento de faces e bordas', 'painting' => 'Pintura / seladora', 'drying' => 'Secagem',
            'assembly' => 'Montagem e fixação de acessórios', 'quality' => 'Conferência visual e de medidas', 'packaging' => 'Embalagem com proteção de cantos'];
        $order = 10;
        foreach ($minutes as $stage => $min) {
            $this->insert('production_spec_steps', [
                'spec_id' => $specId, 'stage' => $stage, 'description' => $labels[$stage] ?? null,
                'tool' => $stage === 'cnc' ? 'Fresa 1/8" 2 cortes' : null, 'estimated_minutes' => $min,
                'is_passive' => $stage === 'drying' ? 1 : 0, 'sort_order' => $order,
            ]);
            $order += 10;
        }
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, array{id: int, values: array<string, int>}>
     */
    private function personalization(int $productId, array $fields): array
    {
        $rules = [];
        foreach ($fields as $i => $f) {
            $ruleId = $this->insert('personalization_rules', [
                'product_id' => $productId, 'field_key' => $f['key'], 'label' => $f['label'], 'help_text' => $f['help'] ?? null,
                'type' => $f['type'], 'is_required' => $f['required'] ? 1 : 0,
                'min_length' => in_array($f['type'], ['text', 'initial'], true) ? 1 : null,
                'max_length' => $f['max'] ?? null, 'charset' => $f['charset'] ?? null,
                'price_delta_cents' => $f['price'], 'sort_order' => ($i + 1) * 10,
            ]);
            $values = [];
            foreach ($f['values'] ?? [] as $j => [$code, $label, $delta]) {
                $values[$code] = $this->insert('personalization_values', [
                    'rule_id' => $ruleId, 'code' => $code, 'label' => $label, 'price_delta_cents' => $delta, 'sort_order' => ($j + 1) * 10,
                ]);
            }
            $rules[$f['key']] = ['id' => $ruleId, 'values' => $values];
        }

        return $rules;
    }

    /** Foto das categorias = capa de um produto delas (vitrine da home). */
    private function categoryImages(): void
    {
        // Em dois passos: o MySQL não deixa atualizar categories lendo categories na mesma instrução (erro 1093)
        $find = $this->pdo->prepare(
            'SELECT i.path FROM products p
               JOIN categories pc ON pc.id = p.category_id
               JOIN product_images i ON i.product_id = p.id AND i.is_cover = 1
              WHERE (pc.id = :id OR pc.parent_id = :id2) AND p.is_active = 1 AND p.deleted_at IS NULL
              ORDER BY p.is_featured DESC, p.id LIMIT 1'
        );
        $update = $this->pdo->prepare('UPDATE categories SET image_path = ? WHERE id = ?');
        foreach ($this->pdo->query("SELECT entity_id FROM demo_records WHERE entity = 'categories'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $find->execute(['id' => $id, 'id2' => $id]);
            $path = $find->fetchColumn();
            if ($path !== false) {
                $update->execute([$path, $id]);
            }
        }
    }

    // ---- cupons, clientes e pedidos --------------------------------------------------------

    private function coupons(): void
    {
        foreach ([
            ['BEMVINDO10', '10% na primeira compra', 'percent', 1000, 10000, null],
            ['FRETEGRATIS', 'Frete grátis acima de R$ 150', 'free_shipping', 0, 15000, null],
        ] as [$code, $description, $type, $value, $min, $max]) {
            if ($this->value('SELECT id FROM coupons WHERE code = ?', [$code]) !== null) {
                continue;
            }
            $this->record('coupons', $this->insert('coupons', [
                'code' => $code, 'description' => $description, 'type' => $type, 'value' => $value,
                'min_subtotal_cents' => $min, 'max_discount_cents' => $max, 'is_active' => 1,
            ]));
        }
    }

    /** @return list<array{id: ?int, data: array<int, mixed>, cpf: string}> índice => cliente (id null = compra sem conta) */
    private function customers(): array
    {
        $hasher = $this->container->get(PasswordHasher::class);
        $users = $this->container->get(UserRepository::class);
        $customers = $this->container->get(CustomerRepository::class);
        $addresses = $this->container->get(AddressRepository::class);
        $list = [];
        foreach (DemoCatalog::customers() as $i => $c) {
            [$name, $email, $phone, $zip, $street, $number, $district, $city, $state, $account, $daysAgo] = $c;
            $cpf = self::cpf(40000 + $i * 7919);
            $id = null;
            if ($account && $this->value('SELECT id FROM users WHERE email = ?', [$email]) === null) {
                $userId = $users->create($email, $hasher->hash(self::CUSTOMER_PASSWORD), UserType::Customer);
                $id = $customers->create($userId, $name, $email);
                $this->pdo->prepare('UPDATE customers SET cpf = ?, phone = ?, created_at = ? WHERE id = ?')
                    ->execute([$cpf, '55' . preg_replace('/\D/', '', $phone), $this->ago($daysAgo), $id]);
                $this->pdo->prepare('UPDATE users SET created_at = ?, email_verified_at = ? WHERE id = ?')->execute([$this->ago($daysAgo), $this->ago($daysAgo), $userId]);
                $addresses->create($id, [
                    'recipient_name' => $name, 'zip_code' => preg_replace('/\D/', '', $zip), 'street' => $street, 'number' => $number,
                    'complement' => '', 'district' => $district, 'city' => $city, 'state' => $state,
                ]);
                $this->record('users', $userId);
                $this->record('customers', $id);
            }
            $list[$i] = ['id' => $id, 'data' => $c, 'cpf' => $cpf];
        }
        ($this->log)(count($list) . ' clientes (' . count(array_filter($list, fn ($c) => $c['id'] !== null)) . ' com conta).');

        return $list;
    }

    /**
     * @param array<string, array{id: int, variants: array<string, int>, rules: array<string, array{id: int, values: array<string, int>}>}> $products
     * @param list<array{id: ?int, data: array<int, mixed>, cpf: string}> $customers
     */
    private function orders(array $products, array $customers): int
    {
        $cart = $this->container->get(CartService::class);
        $context = $this->container->get(CartContext::class);
        $checkout = $this->container->get(CheckoutService::class);
        $orderService = $this->container->get(OrderService::class);
        $status = $this->container->get(OrderStatusService::class);
        $production = $this->container->get(ProductionService::class);
        $count = 0;

        foreach (DemoCatalog::orders() as $n => [$customerIndex, $lines, $daysAgo, $target, $shipping, $coupon]) {
            $customer = $customers[$customerIndex];
            [$name, $email, $phone, $zip, $street, $number, $district, $city, $state] = $customer['data'];
            $context->forget();
            foreach ($lines as [$slug, $qty, $variantName, $pers]) {
                $product = $products[$slug] ?? throw new RuntimeException("Produto de demonstração ausente: {$slug}");
                $variantId = $variantName === null ? reset($product['variants']) : ($product['variants'][$variantName] ?? throw new RuntimeException("Variação {$variantName} de {$slug}"));
                $input = [];
                foreach ($pers as $key => $value) {
                    $rule = $product['rules'][$key];
                    $input[$rule['id']] = $rule['values'] === [] ? $value : (string) $rule['values'][$value];
                }
                $cart->add((int) $variantId, $qty, $input, $customer['id']);
            }
            if ($coupon !== null) {
                $cart->applyCoupon($coupon);
            }
            $placed = $checkout->place([
                'name' => $name, 'email' => $email, 'cpf' => $customer['cpf'], 'phone' => $phone, 'zip_code' => $zip, 'street' => $street,
                'number' => $number, 'complement' => '', 'district' => $district, 'city' => $city, 'state' => $state,
                'recipient_name' => '', 'shipping_code' => $shipping, 'quoted_zip' => preg_replace('/\D/', '', $zip),
                // mesmos campos que o CheckoutController envia
                'logged_in' => $customer['id'] !== null, 'account_email' => $email, 'save_address' => false,
            ], $customer['id']);
            $orderId = (int) $placed['order_id'];
            $this->record('orders', $orderId);
            $buyer = (int) $this->value('SELECT customer_id FROM orders WHERE id = ?', [$orderId]);
            if ($customer['id'] === null) {
                $this->record('customers', $buyer); // cadastro de visitante criado pelo checkout
            }

            if ($target === 'cancelado') {
                $orderService->cancelUnpaid($orderId, 'Pagamento não identificado no prazo.', 'system');
            } elseif ($target !== 'aguardando') {
                $this->payment($orderId, $n);
                $orderService->markPaid($orderId, 'webhook');
                if ($target === 'estornado') {
                    $status->cancel($orderId, 'Cliente desistiu da compra antes da produção.', 'admin', null, 'manual');
                } elseif (isset(self::STAGE_TARGET[$target])) {
                    $this->advanceJobs($production, $orderId, self::STAGE_TARGET[$target]);
                    if (in_array($target, ['enviado', 'entregue'], true)) {
                        $status->transition($orderId, OrderStatus::Shipped, 'admin', null, null, [
                            'carrier' => 'Correios', 'service' => $shipping === 'expresso' ? 'SEDEX' : 'PAC',
                            'tracking_code' => sprintf('Q%s%08dBR', $shipping === 'expresso' ? 'S' : 'B', 10235800 + $n * 137),
                        ]);
                    }
                    if ($target === 'entregue') {
                        $status->transition($orderId, OrderStatus::Delivered, 'admin');
                    }
                }
            }
            $this->backdate($orderId, $daysAgo, $n);
            $count++;
        }
        ($this->log)("{$count} pedidos em todas as etapas (aguardando pagamento, produção, expedição, entregues e cancelados).");

        return $count;
    }

    private function payment(int $orderId, int $n): void
    {
        $total = (int) $this->value('SELECT total_cents FROM orders WHERE id = ?', [$orderId]);
        $card = $n % 3 !== 0;
        $this->insert('payments', [
            'order_id' => $orderId, 'provider' => 'mercadopago', 'provider_payment_id' => (string) (91000000000 + $n * 7331),
            'method' => $card ? 'credit_card' : 'pix', 'status' => 'paid', 'amount_cents' => $total,
            'installments' => $card ? [1, 2, 3, 6][$n % 4] : null, 'card_brand' => $card ? ['visa', 'master', 'elo'][$n % 3] : null,
            'card_last4' => $card ? str_pad((string) (1000 + $n * 373 % 9000), 4, '0', STR_PAD_LEFT) : null,
            'paid_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Avança cada ordem de produção do pedido até a etapa desejada (ou a mais próxima da rota). */
    private function advanceJobs(ProductionService $production, int $orderId, string $target): void
    {
        $jobs = $this->pdo->prepare('SELECT id FROM production_jobs WHERE order_id = ? ORDER BY id');
        $jobs->execute([$orderId]);
        foreach ($jobs->fetchAll(PDO::FETCH_COLUMN) as $jobId) {
            for ($guard = 0; $guard < 15; $guard++) {
                $stage = (string) $this->value('SELECT stage FROM production_jobs WHERE id = ?', [$jobId]);
                if ($stage === 'done' || ProductionFlow::rank($stage) >= ProductionFlow::rank($target)) {
                    break;
                }
                $production->advance((int) $jobId, null);
            }
        }
    }

    /**
     * Espalha as datas do pedido: criado há N dias e cada passo do histórico em seguida,
     * proporcionalmente ao tempo até hoje (entregues ficaram prontos em dias, não em minutos).
     */
    private function backdate(int $orderId, int $daysAgo, int $n): void
    {
        $created = time() - $daysAgo * 86400 - (($n * 37) % 9 + 1) * 3600;
        $span = max(3600, (int) ((time() - $created) * 0.85));
        $at = static fn (int $offset): string => gmdate('Y-m-d H:i:s', $created + $offset);

        $this->pdo->prepare('UPDATE orders SET created_at = ?, placed_at = ?, updated_at = ?, cancelled_at = IF(cancelled_at IS NULL, NULL, ?) WHERE id = ?')
            ->execute([$at(0), $at(0), $at($span), $at($span), $orderId]);
        // Cliente sem conta nasce no primeiro pedido
        $this->pdo->prepare('UPDATE customers c JOIN orders o ON o.customer_id = c.id AND o.id = ? SET c.created_at = LEAST(c.created_at, o.placed_at)')
            ->execute([$orderId]);
        $history = $this->pdo->prepare('SELECT id, to_status FROM order_status_history WHERE order_id = ? ORDER BY id');
        $history->execute([$orderId]);
        $rows = $history->fetchAll();
        $step = count($rows) > 1 ? (int) ($span / (count($rows) - 1)) : 0;
        $dates = [];
        foreach ($rows as $i => $row) {
            // pagamento sai em até 2 h; o resto se distribui no período
            $offset = $i === 0 ? 0 : ($row['to_status'] === 'paid' || $row['to_status'] === 'production_pending' ? 3600 + $i * 600 : $step * $i);
            $dates[(string) $row['to_status']] = $at($offset);
            $this->pdo->prepare('UPDATE order_status_history SET created_at = ? WHERE id = ?')->execute([$at($offset), $row['id']]);
        }
        $paid = $dates['paid'] ?? null;
        if ($paid !== null) {
            $this->pdo->prepare('UPDATE orders SET paid_at = ? WHERE id = ?')->execute([$paid, $orderId]);
            $this->pdo->prepare('UPDATE payments SET created_at = ?, paid_at = ?, updated_at = ? WHERE order_id = ?')->execute([$at(0), $paid, $paid, $orderId]);
            $this->pdo->prepare('UPDATE coupon_redemptions SET created_at = ? WHERE order_id = ?')->execute([$at(0), $orderId]);
            // produção começa depois do pagamento e leva perto do prazo prometido (de 1 a 2,2 vezes, em dias corridos):
            // quase tudo no prazo, alguns atrasados. Sem passar do envio nem de agora.
            $days = $this->pdo->prepare('SELECT production_days FROM orders WHERE id = ?');
            $days->execute([$orderId]);
            $paidTs = (int) strtotime($paid . ' UTC');
            $lead = (int) (max(1, (int) $days->fetchColumn()) * 86400 * (1 + (($n * 13) % 7) / 5));
            $limit = (isset($dates['shipped']) ? (int) strtotime($dates['shipped'] . ' UTC') : time()) - 3600;
            $lead = max(3600, min($lead, $limit - $paidTs));
            $job = static fn (float $share): string => gmdate('Y-m-d H:i:s', $paidTs + (int) ($lead * $share));
            $this->pdo->prepare(
                'UPDATE production_jobs SET created_at = ?, started_at = IF(started_at IS NULL, NULL, ?), stage_started_at = ?,
                        finished_at = IF(finished_at IS NULL, NULL, ?) WHERE order_id = ?'
            )->execute([$paid, $job(0.2), $job(0.6), $job(1.0), $orderId]);
            $this->pdo->prepare(
                'UPDATE production_job_events e JOIN production_jobs j ON j.id = e.job_id SET e.created_at = ? WHERE j.order_id = ?'
            )->execute([$job(0.5), $orderId]);
            $this->pdo->prepare(
                "UPDATE material_movements m JOIN production_jobs j ON m.reference_type = 'production_job' AND m.reference_id = j.id
                    SET m.created_at = ? WHERE j.order_id = ?"
            )->execute([$job(0.3), $orderId]);
        }
        $this->pdo->prepare("UPDATE inventory_movements SET created_at = ? WHERE reference_type = 'order' AND reference_id = ?")->execute([$at(0), $orderId]);
        $shipped = $dates['shipped'] ?? null;
        if ($shipped !== null) {
            $this->pdo->prepare('UPDATE shipments SET created_at = ?, shipped_at = ?, delivered_at = IF(delivered_at IS NULL, NULL, ?) WHERE order_id = ?')
                ->execute([$shipped, $shipped, $dates['delivered'] ?? $shipped, $orderId]);
        }
    }

    // ---- remoção ---------------------------------------------------------------------------

    /**
     * Apaga tudo que a demonstração criou. Produto de demonstração que entrou num pedido real é
     * desativado (não pode sumir do histórico) e contado em "mantidos".
     *
     * @return array<string, int>
     */
    public function remove(): array
    {
        if (!$this->isInstalled()) {
            return [];
        }
        $ids = fn (string $entity): array => array_map('intval', $this->pdo->query(
            'SELECT entity_id FROM demo_records WHERE entity = ' . $this->pdo->quote($entity)
        )->fetchAll(PDO::FETCH_COLUMN));
        $in = static fn (array $list): string => $list === [] ? '0' : implode(',', array_map('intval', $list));
        $images = $this->container->get(ImageProcessor::class);
        $summary = ['pedidos' => 0, 'clientes' => 0, 'produtos' => 0, 'mantidos' => 0, 'categorias' => 0, 'matérias-primas' => 0, 'cupons' => 0];

        $this->pdo->beginTransaction();
        try {
            $orders = $in($ids('orders'));
            $this->pdo->exec("DELETE e FROM production_job_events e JOIN production_jobs j ON j.id = e.job_id WHERE j.order_id IN ({$orders})");
            $this->pdo->exec("UPDATE material_movements m JOIN production_jobs j ON m.reference_type = 'production_job' AND m.reference_id = j.id SET m.reference_id = NULL WHERE j.order_id IN ({$orders})");
            $this->pdo->exec("DELETE FROM production_jobs WHERE order_id IN ({$orders})");
            $this->pdo->exec("DELETE FROM coupon_redemptions WHERE order_id IN ({$orders})");
            $this->pdo->exec("DELETE FROM shipments WHERE order_id IN ({$orders})");
            $this->pdo->exec("DELETE FROM payment_events WHERE payment_id IN (SELECT id FROM payments WHERE order_id IN ({$orders}))");
            $this->pdo->exec("DELETE FROM payments WHERE order_id IN ({$orders})");
            $this->pdo->exec("DELETE FROM inventory_movements WHERE reference_type = 'order' AND reference_id IN ({$orders})");
            $this->pdo->exec("DELETE FROM order_items WHERE order_id IN ({$orders})");
            $summary['pedidos'] = (int) $this->pdo->exec("DELETE FROM orders WHERE id IN ({$orders})");

            $customers = $in($ids('customers'));
            $this->pdo->exec("DELETE FROM carts WHERE customer_id IN ({$customers})");
            // cliente de demonstração que depois fez pedido real fica
            $summary['clientes'] = (int) $this->pdo->exec("DELETE FROM customers WHERE id IN ({$customers}) AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = customers.id)");
            $this->pdo->exec('DELETE FROM users WHERE id IN (' . $in($ids('users')) . ') AND NOT EXISTS (SELECT 1 FROM customers c WHERE c.user_id = users.id)');

            $coupons = $in($ids('coupons'));
            $summary['cupons'] = (int) $this->pdo->exec("DELETE FROM coupons WHERE id IN ({$coupons}) AND NOT EXISTS (SELECT 1 FROM coupon_redemptions r WHERE r.coupon_id = coupons.id)");

            $paths = [];
            foreach ($ids('products') as $productId) {
                $usedByRealOrder = (int) $this->value('SELECT COUNT(*) FROM order_items WHERE product_id = ?', [$productId]) > 0;
                if ($usedByRealOrder) {
                    $this->pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$productId]);
                    $summary['mantidos']++;
                    continue;
                }
                $variants = $in(array_map('intval', $this->pdo->query("SELECT id FROM product_variants WHERE product_id = {$productId}")->fetchAll(PDO::FETCH_COLUMN)));
                $paths = [...$paths, ...$this->pdo->query("SELECT path FROM product_images WHERE product_id = {$productId}")->fetchAll(PDO::FETCH_COLUMN)];
                $this->pdo->exec("DELETE FROM inventory_movements WHERE variant_id IN ({$variants})");
                $this->pdo->exec("DELETE s FROM production_spec_steps s JOIN production_specs p ON p.id = s.spec_id WHERE p.variant_id IN ({$variants})");
                $this->pdo->exec("DELETE FROM production_specs WHERE variant_id IN ({$variants})");
                $this->pdo->exec("DELETE FROM variant_option_values WHERE variant_id IN ({$variants})");
                $this->pdo->exec("DELETE FROM product_variants WHERE id IN ({$variants})");
                $this->pdo->exec("DELETE FROM products WHERE id = {$productId}"); // opções, regras e imagens em cascata
                $summary['produtos']++;
            }

            // subcategorias antes das principais; só as que ficaram vazias
            foreach (array_reverse($ids('categories')) as $categoryId) {
                $summary['categorias'] += (int) $this->pdo->exec(
                    "DELETE FROM categories WHERE id = {$categoryId}
                        AND NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id = {$categoryId})
                        AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM categories WHERE parent_id = {$categoryId}) c)"
                );
            }
            foreach ($ids('materials') as $materialId) {
                if ((int) $this->value('SELECT COUNT(*) FROM production_specs WHERE material_id = ?', [$materialId]) === 0) {
                    $this->pdo->exec("DELETE FROM material_movements WHERE material_id = {$materialId}");
                    $summary['matérias-primas'] += (int) $this->pdo->exec("DELETE FROM materials WHERE id = {$materialId}");
                }
            }
            $this->pdo->exec('DELETE FROM demo_records');
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        foreach ($paths as $path) {
            $images->delete((string) $path);
            $dir = (string) $this->config->get('paths.uploads') . '/' . dirname((string) $path);
            if (is_dir($dir) && count(scandir($dir) ?: []) <= 2) {
                @rmdir($dir); // pasta products/{id} vazia
            }
        }

        return $summary;
    }

    // ---- utilidades ------------------------------------------------------------------------

    /** "MDF 3 mm" → MDF3, "P (30 cm)" → P30, "Preto fosco" → PRE: três letras da 1ª palavra + os números. */
    public static function skuSuffix(string $value): string
    {
        $ascii = strtoupper((string) (iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value));
        $letters = substr((string) preg_replace('/[^A-Z]/', '', explode(' ', trim($ascii))[0]), 0, 3);

        return $letters . preg_replace('/\D/', '', $ascii);
    }

    /** CPF válido (dígitos verificadores corretos) a partir de uma semente. */
    public static function cpf(int $seed): string
    {
        $base = str_pad((string) (($seed * 7919 + 123456789) % 999999999), 9, '0', STR_PAD_LEFT);
        if (count(array_unique(str_split($base))) === 1) {
            $base = '123456780';
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $base[$i] * ($t + 1 - $i);
            }
            $base .= (string) ((10 * $sum) % 11 % 10);
        }

        return $base;
    }

    private function ago(int $days): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    private function record(string $entity, int $id): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO demo_records (entity, entity_id) VALUES (?, ?)')->execute([$entity, $id]);
    }

    /** @param array<string, mixed> $data */
    private function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $this->pdo->prepare(
            'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        )->execute(array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, array_values($data)));

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<mixed> $params */
    private function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    private function tableExists(): bool
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'demo_records'")->fetchColumn() > 0;
    }
}
