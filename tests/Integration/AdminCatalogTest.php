<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Enums\AdminRole;
use GNesting\Services\CustomerPrivacyService;

/**
 * Etapa 14, fase 3 (docs/19): produto em abas (custo, margem, prazos, montagem, cuidados, palavras-chave),
 * duplicar produto, categorias em árvore e observações na ficha do cliente.
 */
final class AdminCatalogTest extends HttpTestCase
{
    private function productId(): int
    {
        return (int) $this->fetchValue("SELECT product_id FROM product_variants WHERE sku = 'REL-GEO-001'");
    }

    /** @return array<string, string> formulário com os valores atuais do produto */
    private function currentForm(int $id, array $overrides = []): array
    {
        $p = $this->db->pdo()->query(
            "SELECT p.*, v.sku, v.price_cents, v.compare_at_price_cents, v.cost_cents, v.material_label, v.finish_label, v.width_mm, v.height_mm,
                    v.depth_mm, v.weight_g, v.package_width_mm, v.package_height_mm, v.package_length_mm, v.package_weight_g, i.stock_mode, i.quantity_on_hand
               FROM products p JOIN product_variants v ON v.product_id = p.id AND v.is_default = 1 LEFT JOIN inventory i ON i.variant_id = v.id
              WHERE p.id = {$id}"
        )->fetch();
        $form = ['price' => money_input((int) $p['price_cents']), 'compare_at_price' => money_input($p['compare_at_price_cents'] === null ? null : (int) $p['compare_at_price_cents']),
            'cost' => money_input($p['cost_cents'] === null ? null : (int) $p['cost_cents']), 'is_featured' => $p['is_featured'] ? '1' : '', 'is_new' => $p['is_new'] ? '1' : ''];
        foreach (['name', 'slug', 'category_id', 'short_description', 'description', 'highlights', 'keywords', 'care_instructions', 'assembly_info',
            'production_lead_days', 'dispatch_days', 'meta_title', 'meta_description', 'sku', 'material_label', 'finish_label', 'width_mm', 'height_mm',
            'depth_mm', 'weight_g', 'package_width_mm', 'package_height_mm', 'package_length_mm', 'package_weight_g', 'stock_mode', 'quantity_on_hand'] as $field) {
            $form[$field] = (string) ($p[$field] ?? '');
        }

        return $overrides + $form;
    }

    public function testProductTabsSaveCostTimesAndStoreDetails(): void
    {
        $this->loginAdmin(AdminRole::Manager);
        $id = $this->productId();
        $page = $this->get("/admin/produtos/{$id}/editar")->body();
        foreach (['data-tab-panel="geral"', 'data-tab-panel="comercial"', 'data-tab-panel="estoque"', 'data-tab-panel="seo"', '>Produção<', 'Como aparece no Google'] as $text) {
            self::assertStringContainsString($text, $page, $text);
        }

        $this->post("/admin/produtos/{$id}/editar", $this->currentForm($id, [
            'price' => '129,90', 'cost' => '52,00', 'dispatch_days' => '2', 'keywords' => 'relógio de parede, sala',
            'assembly_info' => 'Chega montado.', 'care_instructions' => 'Pano seco.',
        ]));
        $row = $this->db->pdo()->query("SELECT p.dispatch_days, p.keywords, p.assembly_info, p.care_instructions, v.cost_cents
            FROM products p JOIN product_variants v ON v.product_id = p.id AND v.is_default = 1 WHERE p.id = {$id}")->fetch();
        self::assertSame([2, 'relógio de parede, sala', 'Chega montado.', 'Pano seco.', 5200],
            [(int) $row['dispatch_days'], $row['keywords'], $row['assembly_info'], $row['care_instructions'], (int) $row['cost_cents']]);
        self::assertStringContainsString('margem 60%', $this->get("/admin/produtos/{$id}/editar")->body(), '(129,90 − 52,00) ÷ 129,90');
        self::assertStringContainsString('60%', $this->get('/admin/produtos')->body(), 'Margem também na lista');

        // A loja mostra montagem e cuidados; a busca acha pela palavra-chave; o custo nunca aparece
        $this->db->pdo()->exec("UPDATE products SET is_active = 1 WHERE id = {$id}");
        $store = $this->get('/produto/relogio-geometrico-g-nesting')->body();
        self::assertStringContainsString('Chega montado.', $store);
        self::assertStringNotContainsString('52,00', $store);
        self::assertStringContainsString('Relógio Geométrico G-Nesting', $this->get('/busca', ['q' => 'sala'])->body());

        $this->post("/admin/produtos/{$id}/editar", $this->currentForm($id, ['cost' => 'abc']));
        self::assertStringContainsString('field--invalid', $this->get("/admin/produtos/{$id}/editar")->body(), 'Custo inválido volta com erro');
    }

    public function testDuplicateCopiesVariantsPersonalizationAndSpecsButNotPhotosOrStock(): void
    {
        $this->loginAdmin(AdminRole::Manager);
        $id = $this->productId();
        $before = (int) $this->fetchValue('SELECT COUNT(*) FROM products');

        $response = $this->post("/admin/produtos/{$id}/duplicar");
        $copyId = (int) $this->fetchValue('SELECT MAX(id) FROM products');
        self::assertSame($before + 1, (int) $this->fetchValue('SELECT COUNT(*) FROM products'));
        self::assertStringEndsWith("/admin/produtos/{$copyId}/editar", (string) $response->header('Location'));

        $copy = $this->db->pdo()->query("SELECT name, slug, is_active FROM products WHERE id = {$copyId}")->fetch();
        self::assertSame('Relógio Geométrico G-Nesting (cópia)', $copy['name']);
        self::assertSame(0, (int) $copy['is_active']);
        self::assertNotSame('relogio-geometrico-g-nesting', $copy['slug']);

        $count = fn (string $sql, int $product): int => (int) $this->fetchValue($sql, ['p' => $product]);
        foreach ([
            'SELECT COUNT(*) FROM product_variants WHERE product_id = :p AND deleted_at IS NULL',
            'SELECT COUNT(*) FROM personalization_rules WHERE product_id = :p',
            'SELECT COUNT(*) FROM production_specs s JOIN product_variants v ON v.id = s.variant_id WHERE v.product_id = :p',
        ] as $sql) {
            self::assertSame($count($sql, $id), $count($sql, $copyId), $sql);
        }
        self::assertSame(0, $count('SELECT COUNT(*) FROM product_images WHERE product_id = :p', $copyId), 'Sem fotos');
        self::assertSame(0, $count('SELECT COALESCE(SUM(i.quantity_on_hand), 0) FROM inventory i JOIN product_variants v ON v.id = i.variant_id WHERE v.product_id = :p', $copyId));
        self::assertSame('REL-GEO-001-C', $this->fetchValue('SELECT sku FROM product_variants WHERE product_id = :p AND is_default = 1', ['p' => $copyId]));

        $this->post("/admin/produtos/{$id}/duplicar");
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM product_variants WHERE sku = 'REL-GEO-001-C2'"), 'Segunda cópia com SKU próprio');

        $this->loginAdmin(AdminRole::Support);
        self::assertSame(403, $this->post("/admin/produtos/{$id}/duplicar")->status());
    }

    public function testCategoriesAppearAsTreeAndSubcategoryStartsWithItsParent(): void
    {
        $this->loginAdmin(AdminRole::Manager);
        $parentId = (int) $this->fetchValue("SELECT id FROM categories WHERE parent_id IS NULL ORDER BY id LIMIT 1");
        $this->db->pdo()->exec("INSERT INTO categories (parent_id, name, slug, sort_order, is_active) VALUES ({$parentId}, 'Filha de teste', 'filha-de-teste', 1, 1)");

        $page = $this->get('/admin/categorias')->body();
        self::assertStringContainsString('category-node__children', $page);
        self::assertStringContainsString('Filha de teste', $page);
        self::assertMatchesRegularExpression('#<option value="' . $parentId . '"\s+selected#', $this->get('/admin/categorias/novo', ['mae' => (string) $parentId])->body());
    }

    public function testCustomerNotesAreInternalAuditedExportedAndErasedOnAnonymization(): void
    {
        $this->registerCustomer('notas@cliente.test');
        $customerId = (int) $this->fetchValue("SELECT id FROM customers WHERE email = 'notas@cliente.test'");

        $this->loginAdmin(AdminRole::Support);
        $this->post("/admin/clientes/{$customerId}/observacoes", ['notes' => 'Prefere contato pelo WhatsApp.']);
        self::assertStringContainsString('Prefere contato pelo WhatsApp.', $this->get("/admin/clientes/{$customerId}")->body());
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'customer' AND entity_id = :id", ['id' => $customerId]));

        $this->loginAdmin(AdminRole::Production);
        self::assertSame(403, $this->post("/admin/clientes/{$customerId}/observacoes", ['notes' => 'x'])->status());

        $privacy = $this->container->get(CustomerPrivacyService::class);
        self::assertSame('Prefere contato pelo WhatsApp.', $privacy->export($customerId)['cadastro']['observacoes_da_loja']);
        $privacy->anonymize($customerId);
        self::assertNull($this->fetchValue('SELECT notes FROM customers WHERE id = :id', ['id' => $customerId]));
    }
}
