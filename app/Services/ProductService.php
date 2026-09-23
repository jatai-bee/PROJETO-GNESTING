<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\AuditContext;
use GNesting\Core\Database;
use GNesting\Core\ValidationException;
use GNesting\Repositories\CategoryRepository;
use GNesting\Repositories\InventoryRepository;
use GNesting\Repositories\ProductImageRepository;
use GNesting\Repositories\ProductRepository;
use GNesting\Repositories\ProductVariantRepository;

/**
 * Cadastro de produtos com a variante padrão (SKU, preço, medidas) e o estoque.
 *
 * Regras:
 * - produto nasce INATIVO; só é ativado com imagem, preço e categoria ativa
 * - SKU é único para sempre (inclusive de produtos excluídos)
 * - alteração de preço e de estoque têm auditoria própria; estoque gera movimentação
 */
final class ProductService
{
    private const PRODUCT_FIELDS = [
        'category_id', 'name', 'slug', 'short_description', 'description', 'highlights',
        'production_lead_days', 'is_featured', 'is_new', 'meta_title', 'meta_description',
    ];

    private const VARIANT_FIELDS = [
        'sku', 'price_cents', 'compare_at_price_cents', 'material_label', 'finish_label',
        'width_mm', 'height_mm', 'depth_mm', 'weight_g',
        'package_width_mm', 'package_height_mm', 'package_length_mm', 'package_weight_g',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
        private readonly InventoryRepository $inventory,
        private readonly ProductImageRepository $images,
        private readonly CategoryRepository $categories,
        private readonly AuditService $audit,
        private readonly AuditContext $auditContext,
    ) {
    }

    /** @param array<string, mixed> $input ver ProductController::input() */
    public function create(array $input): int
    {
        return $this->db->transaction(function () use ($input): int {
            [$product, $variant] = $this->prepare($input, null, null);

            $productId = $this->products->create($product);
            $variantId = $this->variants->createDefault($productId, $variant);

            $quantity = $input['stock_mode'] === 'stock' ? $input['quantity_on_hand'] : 0;
            $this->inventory->create($variantId, $input['stock_mode'], $quantity);
            if ($quantity > 0) {
                $this->inventory->addMovement($variantId, 'in', $quantity, 'Estoque inicial', $this->auditContext->userId());
            }

            $this->audit->record(AuditService::CREATE, 'product', $productId, null, $product + $variant + [
                'stock_mode' => $input['stock_mode'], 'quantity_on_hand' => $quantity,
            ]);

            return $productId;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): void
    {
        $this->db->transaction(function () use ($id, $input): void {
            $current = $this->products->findForAdmin($id) ?? throw new BusinessRuleException('Produto não encontrado.');
            $variantId = (int) $current['variant_id'];
            [$product, $variant] = $this->prepare($input, $id, $variantId, (string) $current['slug']);

            $this->products->update($id, $product);
            $this->variants->update($variantId, $variant);

            // Preço: auditoria específica (price_change)
            $priceFields = ['price_cents', 'compare_at_price_cents'];
            $this->audit->recordChanges(
                AuditService::PRICE_CHANGE, 'product', $id,
                array_intersect_key($current, array_flip($priceFields)),
                array_intersect_key($variant, array_flip($priceFields)),
            );

            // Demais campos
            $this->audit->recordChanges(
                AuditService::UPDATE, 'product', $id,
                $current,
                $product + array_diff_key($variant, array_flip($priceFields)),
            );

            $this->updateStock($id, $variantId, $current, $input['stock_mode'], $input['quantity_on_hand']);
        });
    }

    /** @throws BusinessRuleException quando faltam requisitos para ativar */
    public function setActive(int $id, bool $active): void
    {
        $this->db->transaction(function () use ($id, $active): void {
            $product = $this->products->findForAdmin($id) ?? throw new BusinessRuleException('Produto não encontrado.');
            if ((bool) $product['is_active'] === $active) {
                return;
            }

            if ($active) {
                $missing = [];
                if ($this->images->countByProduct($id) === 0) {
                    $missing[] = 'pelo menos uma imagem';
                }
                if ((int) $product['price_cents'] <= 0) {
                    $missing[] = 'preço';
                }
                $category = $this->categories->find((int) $product['category_id']);
                if ($category === null || !(bool) $category['is_active']) {
                    $missing[] = 'categoria ativa';
                }
                if ($missing !== []) {
                    throw new BusinessRuleException('Para ativar o produto, falta: ' . implode(', ', $missing) . '.');
                }
            }

            $this->products->setActive($id, $active);
            $this->audit->record(AuditService::STATUS_CHANGE, 'product', $id, ['is_active' => (int) !$active], ['is_active' => (int) $active]);
        });
    }

    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $product = $this->products->findForAdmin($id) ?? throw new BusinessRuleException('Produto não encontrado.');
            $this->products->softDelete($id);
            $this->variants->softDeleteByProduct($id);
            $this->audit->record(AuditService::DELETE, 'product', $id, [
                'name' => $product['name'], 'sku' => $product['sku'], 'slug' => $product['slug'],
            ]);
        });
    }

    /**
     * @param array<string, mixed> $current
     */
    private function updateStock(int $productId, int $variantId, array $current, string $mode, int $quantity): void
    {
        $oldMode = (string) ($current['stock_mode'] ?? 'made_to_order');
        $oldQuantity = (int) ($current['quantity_on_hand'] ?? 0);
        if ($mode === 'made_to_order') {
            $quantity = $oldQuantity; // produção sob pedido: quantidade física não é editada aqui
        }

        if ($mode === $oldMode && $quantity === $oldQuantity) {
            return;
        }

        if ($current['stock_mode'] === null) {
            $this->inventory->create($variantId, $mode, $quantity);
        } else {
            $this->inventory->update($variantId, $mode, $quantity);
        }

        if ($quantity !== $oldQuantity) {
            $this->inventory->addMovement($variantId, 'adjust', $quantity - $oldQuantity, 'Ajuste manual no painel', $this->auditContext->userId());
        }

        $this->audit->record(
            AuditService::STOCK_CHANGE, 'product', $productId,
            ['stock_mode' => $oldMode, 'quantity_on_hand' => $oldQuantity],
            ['stock_mode' => $mode, 'quantity_on_hand' => $quantity],
        );
    }

    /**
     * Valida regras que dependem do banco e separa dados de produto e de variante.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function prepare(array $input, ?int $productId, ?int $variantId, ?string $currentSlug = null): array
    {
        $errors = [];

        if (!$this->products->categoryIsUsable((int) $input['category_id'])) {
            $errors['category_id'] = 'Escolha uma categoria válida.';
        }

        // Na edição, campo vazio mantém o endereço atual: renomear o produto
        // não pode quebrar links já divulgados nem a indexação nos buscadores.
        $slug = (string) $input['slug'] ?: (string) $currentSlug;
        if ($slug === '') {
            $slug = SlugGenerator::unique((string) $input['name'], fn (string $s) => $this->products->slugExists($s, $productId), 170);
        } elseif ($this->products->slugExists($slug, $productId)) {
            $errors['slug'] = 'Este endereço (slug) já está em uso por outro produto.';
        }

        $sku = strtoupper((string) $input['sku']);
        if ($this->variants->skuExists($sku, $variantId)) {
            $errors['sku'] = 'Este SKU já foi usado (inclusive por produtos excluídos). Escolha outro.';
        }

        if ($input['compare_at_price_cents'] !== null && $input['compare_at_price_cents'] <= $input['price_cents']) {
            $errors['compare_at_price'] = 'O preço "de" precisa ser maior que o preço de venda.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $product = array_intersect_key($input, array_flip(self::PRODUCT_FIELDS));
        $product['slug'] = $slug;
        $product['is_featured'] = (int) $input['is_featured'];
        $product['is_new'] = (int) $input['is_new'];
        foreach (['short_description', 'description', 'highlights', 'meta_title', 'meta_description'] as $field) {
            $product[$field] = ($product[$field] ?? '') === '' ? null : $product[$field];
        }

        $variant = array_intersect_key($input, array_flip(self::VARIANT_FIELDS));
        $variant['sku'] = $sku;
        foreach (['material_label', 'finish_label'] as $field) {
            $variant[$field] = ($variant[$field] ?? '') === '' ? null : $variant[$field];
        }

        return [$product, $variant];
    }
}
