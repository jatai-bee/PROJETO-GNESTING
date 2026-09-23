<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Core\ValidationException;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\CategoryService;
use GNesting\Services\ProductService;

final class CatalogServicesTest extends IntegrationTestCase
{
    private CategoryService $categories;
    private ProductService $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categories = $this->container->get(CategoryService::class);
        $this->products = $this->container->get(ProductService::class);
    }

    /** @return array<string, mixed> */
    private function category(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Luminárias', 'slug' => '', 'parent_id' => null, 'description' => null,
            'sort_order' => 0, 'is_active' => true, 'meta_title' => null, 'meta_description' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function product(int $categoryId, array $overrides = []): array
    {
        return $overrides + [
            'category_id' => $categoryId, 'name' => 'Luminária Nesting', 'slug' => '',
            'short_description' => 'Resumo', 'description' => '', 'highlights' => '', 'production_lead_days' => 4,
            'is_featured' => false, 'is_new' => true, 'meta_title' => '', 'meta_description' => '',
            'sku' => 'lum-nest-001', 'price_cents' => 18990, 'compare_at_price_cents' => null,
            'material_label' => 'MDF 6 mm', 'finish_label' => '', 'width_mm' => 300, 'height_mm' => 400,
            'depth_mm' => null, 'weight_g' => 800, 'package_width_mm' => null, 'package_height_mm' => null,
            'package_length_mm' => null, 'package_weight_g' => null, 'stock_mode' => 'made_to_order', 'quantity_on_hand' => 0,
        ];
    }

    // ---- Categorias ---------------------------------------------------------

    public function testCategorySlugIsGeneratedAndMadeUnique(): void
    {
        $a = $this->categories->create($this->category(['name' => 'Luminárias & Abajures']));
        $b = $this->categories->create($this->category(['name' => 'Luminárias & Abajures']));

        self::assertSame('luminarias-abajures', $this->fetchValue('SELECT slug FROM categories WHERE id = :id', ['id' => $a]));
        self::assertSame('luminarias-abajures-2', $this->fetchValue('SELECT slug FROM categories WHERE id = :id', ['id' => $b]));
    }

    public function testExplicitDuplicateSlugIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->categories->create($this->category(['slug' => 'relogios']));
    }

    public function testOnlyTwoLevelsAreAllowed(): void
    {
        $parent = $this->categories->create($this->category(['name' => 'Pai']));
        $child = $this->categories->create($this->category(['name' => 'Filha', 'parent_id' => $parent]));

        try {
            $this->categories->create($this->category(['name' => 'Neta', 'parent_id' => $child]));
            self::fail('Terceiro nível não deveria ser aceito');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('parent_id', $e->errors());
        }

        $other = $this->categories->create($this->category(['name' => 'Outra']));
        $this->expectException(ValidationException::class);
        $this->categories->update($parent, $this->category(['name' => 'Pai', 'parent_id' => $other])); // pai com filhos não pode virar filho
    }

    public function testCategoryWithProductsCannotBeDeleted(): void
    {
        $relogios = (int) $this->fetchValue("SELECT id FROM categories WHERE slug = 'relogios'");

        $this->expectException(BusinessRuleException::class);
        $this->categories->delete($relogios);
    }

    public function testDeletingCategoryFreesSlugAndIsAudited(): void
    {
        $id = $this->categories->create($this->category(['name' => 'Temporária', 'slug' => 'temporaria']));
        $this->categories->delete($id);

        self::assertNotNull($this->fetchValue('SELECT deleted_at FROM categories WHERE id = :id', ['id' => $id]));
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'delete' AND entity_type = 'category' AND entity_id = :id", ['id' => $id]));
        $this->categories->create($this->category(['name' => 'Temporária', 'slug' => 'temporaria'])); // slug reutilizável
    }

    // ---- Produtos -----------------------------------------------------------

    public function testCreateProductWithDefaultVariantAndInventory(): void
    {
        $categoryId = $this->categories->create($this->category());
        $id = $this->products->create($this->product($categoryId));

        $row = $this->db->pdo()->query(
            "SELECT p.is_active, p.slug, v.sku, v.price_cents, v.is_default, i.stock_mode
               FROM products p JOIN product_variants v ON v.product_id = p.id JOIN inventory i ON i.variant_id = v.id
              WHERE p.id = {$id}"
        )->fetch();

        self::assertSame(0, $row['is_active'], 'Produto nasce inativo');
        self::assertSame('luminaria-nesting', $row['slug']);
        self::assertSame('LUM-NEST-001', $row['sku'], 'SKU normalizado em maiúsculas');
        self::assertSame(18990, $row['price_cents']);
        self::assertSame(1, $row['is_default']);
        self::assertSame('made_to_order', $row['stock_mode']);
    }

    public function testSkuMustBeUniqueEvenAfterDeletion(): void
    {
        $categoryId = $this->categories->create($this->category());
        $id = $this->products->create($this->product($categoryId, ['sku' => 'UNICO-1']));
        $this->products->delete($id);

        try {
            $this->products->create($this->product($categoryId, ['sku' => 'unico-1', 'name' => 'Outro']));
            self::fail('SKU de produto excluído não pode ser reutilizado');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('sku', $e->errors());
        }
    }

    public function testDeletedProductFreesSlug(): void
    {
        $categoryId = $this->categories->create($this->category());
        $id = $this->products->create($this->product($categoryId, ['slug' => 'luminaria-x', 'sku' => 'LX-1']));
        $this->products->delete($id);

        $newId = $this->products->create($this->product($categoryId, ['slug' => 'luminaria-x', 'sku' => 'LX-2']));
        self::assertSame('luminaria-x', $this->fetchValue('SELECT slug FROM products WHERE id = :id', ['id' => $newId]));
    }

    public function testCompareAtPriceMustBeHigher(): void
    {
        $categoryId = $this->categories->create($this->category());

        $this->expectException(ValidationException::class);
        $this->products->create($this->product($categoryId, ['compare_at_price_cents' => 18990]));
    }

    public function testPriceAndStockChangesAreAuditedSeparately(): void
    {
        $categoryId = $this->categories->create($this->category());
        $id = $this->products->create($this->product($categoryId));

        $this->products->update($id, $this->product($categoryId, [
            'price_cents' => 19990, 'stock_mode' => 'stock', 'quantity_on_hand' => 7, 'name' => 'Luminária Nesting II',
        ]));

        $price = json_decode((string) $this->fetchValue(
            "SELECT new_values FROM audit_logs WHERE action = 'price_change' AND entity_id = :id", ['id' => $id]
        ), true);
        self::assertSame(['price_cents' => 19990], $price);

        $stock = json_decode((string) $this->fetchValue(
            "SELECT new_values FROM audit_logs WHERE action = 'stock_change' AND entity_id = :id", ['id' => $id]
        ), true);
        self::assertSame(['stock_mode' => 'stock', 'quantity_on_hand' => 7], $stock);

        self::assertSame(7, (int) $this->fetchValue(
            "SELECT m.quantity FROM inventory_movements m JOIN product_variants v ON v.id = m.variant_id WHERE v.product_id = :id AND m.type = 'adjust'",
            ['id' => $id]
        ));

        $update = json_decode((string) $this->fetchValue(
            "SELECT new_values FROM audit_logs WHERE action = 'update' AND entity_type = 'product' AND entity_id = :id", ['id' => $id]
        ), true);
        self::assertSame(['name' => 'Luminária Nesting II'], $update, 'Auditoria registra só o que mudou');
    }

    public function testUpdateWithoutChangesDoesNotCreateAuditNoise(): void
    {
        $categoryId = $this->categories->create($this->category());
        $id = $this->products->create($this->product($categoryId));
        $this->products->update($id, $this->product($categoryId));

        self::assertSame(0, (int) $this->fetchValue(
            "SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'product' AND entity_id = :id AND action <> 'create'", ['id' => $id]
        ));
    }

    public function testActivationRequiresImage(): void
    {
        $categoryId = $this->categories->create($this->category());
        $id = $this->products->create($this->product($categoryId));

        try {
            $this->products->setActive($id, true);
            self::fail('Não deveria ativar sem imagem');
        } catch (BusinessRuleException $e) {
            self::assertStringContainsString('imagem', $e->getMessage());
        }

        $this->db->pdo()->exec("INSERT INTO product_images (product_id, path, alt_text, is_cover) VALUES ({$id}, 'products/x/a-1600.webp', 'x', 1)");
        $this->products->setActive($id, true);

        self::assertSame(1, (int) $this->fetchValue('SELECT is_active FROM products WHERE id = :id', ['id' => $id]));
        self::assertNotNull($this->fetchValue('SELECT published_at FROM products WHERE id = :id', ['id' => $id]));
    }

    public function testActivationRequiresActiveCategory(): void
    {
        $categoryId = $this->categories->create($this->category(['is_active' => false]));
        $id = $this->products->create($this->product($categoryId));
        $this->db->pdo()->exec("INSERT INTO product_images (product_id, path, alt_text, is_cover) VALUES ({$id}, 'products/x/b-1600.webp', 'x', 1)");

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('categoria ativa');
        $this->products->setActive($id, true);
    }
}
