<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/**
 * Consultas da loja (somente leitura). Um produto é visível quando:
 * está ativo e não excluído, tem variante padrão ativa e a categoria
 * (e a categoria-mãe, se houver) está ativa e não excluída.
 */
final class CatalogRepository extends Repository
{
    public const SORTS = ['relevancia', 'novidades', 'menor-preco', 'maior-preco', 'mais-vendidos'];

    private const VISIBLE_FROM = ' FROM products p
        JOIN categories c ON c.id = p.category_id AND c.is_active = 1 AND c.deleted_at IS NULL
        LEFT JOIN categories cp ON cp.id = c.parent_id
        JOIN product_variants v ON v.product_id = p.id AND v.is_default = 1 AND v.is_active = 1 AND v.deleted_at IS NULL
        LEFT JOIN inventory i ON i.variant_id = v.id';

    private const VISIBLE_WHERE = ' WHERE p.is_active = 1 AND p.deleted_at IS NULL
        AND (c.parent_id IS NULL OR (cp.is_active = 1 AND cp.deleted_at IS NULL))';

    // Faixa de preço e disponibilidade considerando todas as variantes ativas
    private const PRICE_JOIN = ' JOIN (
            SELECT va.product_id, MIN(va.price_cents) AS min_price, MAX(va.price_cents) AS max_price,
                   COUNT(*) AS variant_count,
                   SUM(COALESCE(ia.stock_mode, \'made_to_order\') = \'made_to_order\'
                       OR COALESCE(ia.quantity_on_hand, 0) - COALESCE(ia.quantity_reserved, 0) > 0) AS sellable_count
              FROM product_variants va
              LEFT JOIN inventory ia ON ia.variant_id = va.id
             WHERE va.is_active = 1 AND va.deleted_at IS NULL
             GROUP BY va.product_id
        ) pv ON pv.product_id = p.id';

    private const CARD_FIELDS = 'SELECT p.id, p.name, p.slug, p.short_description, p.is_new, p.is_featured,
            p.production_lead_days, c.name AS category_name, c.slug AS category_slug,
            v.id AS variant_id, v.price_cents, v.compare_at_price_cents,
            pv.min_price, pv.max_price, pv.variant_count, pv.sellable_count,
            COALESCE(i.stock_mode, \'made_to_order\') AS stock_mode,
            GREATEST(COALESCE(i.quantity_on_hand, 0) - COALESCE(i.quantity_reserved, 0), 0) AS available,
            cover.path AS cover_path, cover.alt_text AS cover_alt';

    // Capa: a imagem marcada como capa ou, na falta dela, a primeira da ordem
    private const COVER_JOIN = ' LEFT JOIN product_images cover ON cover.id = (
            SELECT pi.id FROM product_images pi WHERE pi.product_id = p.id
             ORDER BY pi.is_cover DESC, pi.sort_order, pi.id LIMIT 1)';

    /**
     * @param array{category_ids?: list<int>, terms?: list<string>, min_cents?: ?int, max_cents?: ?int} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(array $filters): array
    {
        $sql = self::VISIBLE_WHERE;
        $params = [];

        if (!empty($filters['category_ids'])) {
            $placeholders = [];
            foreach (array_values($filters['category_ids']) as $n => $id) {
                $placeholders[] = ':cat' . $n;
                $params['cat' . $n] = $id;
            }
            $sql .= ' AND p.category_id IN (' . implode(', ', $placeholders) . ')';
        }

        // Cada termo precisa aparecer em algum campo (E entre termos, OU entre campos)
        foreach (array_values($filters['terms'] ?? []) as $n => $term) {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $sql .= " AND (p.name LIKE :t{$n}a OR p.short_description LIKE :t{$n}b OR c.name LIKE :t{$n}c
                      OR EXISTS (SELECT 1 FROM product_variants vs WHERE vs.product_id = p.id AND vs.is_active = 1
                                    AND vs.deleted_at IS NULL AND vs.sku LIKE :t{$n}d))";
            foreach (['a', 'b', 'c', 'd'] as $suffix) {
                $params["t{$n}{$suffix}"] = $like;
            }
        }

        if (($filters['min_cents'] ?? null) !== null) {
            $sql .= ' AND pv.min_price >= :min_cents';
            $params['min_cents'] = $filters['min_cents'];
        }
        if (($filters['max_cents'] ?? null) !== null) {
            $sql .= ' AND pv.min_price <= :max_cents';
            $params['max_cents'] = $filters['max_cents'];
        }

        return [$sql, $params];
    }

    /** @param array{category_ids?: list<int>, terms?: list<string>, min_cents?: ?int, max_cents?: ?int} $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->where($filters);

        return (int) $this->fetchValue('SELECT COUNT(*)' . self::VISIBLE_FROM . self::PRICE_JOIN . $where, $params);
    }

    /**
     * @param array{category_ids?: list<int>, terms?: list<string>, min_cents?: ?int, max_cents?: ?int} $filters
     * @return list<array<string, mixed>>
     */
    public function paginate(array $filters, string $sort, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filters);

        $order = match ($sort) {
            'novidades' => 'COALESCE(p.published_at, p.created_at) DESC',
            'menor-preco' => 'pv.min_price ASC',
            'maior-preco' => 'pv.min_price DESC',
            'mais-vendidos' => 'p.sales_count DESC',
            default => 'p.is_featured DESC, p.sales_count DESC, COALESCE(p.published_at, p.created_at) DESC',
        };

        // Na busca, nome que começa com o primeiro termo vem antes
        $terms = array_values($filters['terms'] ?? []);
        if ($sort === 'relevancia' && $terms !== []) {
            $order = '(p.name LIKE :rel) DESC, ' . $order;
            $params['rel'] = addcslashes($terms[0], '%_\\') . '%';
        }

        return $this->fetchAll(
            self::CARD_FIELDS . self::VISIBLE_FROM . self::PRICE_JOIN . self::COVER_JOIN . $where
            . " ORDER BY {$order}, p.id DESC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset]
        );
    }

    /** @return list<array<string, mixed>> */
    public function featured(int $limit): array
    {
        return $this->fetchAll(
            self::CARD_FIELDS . self::VISIBLE_FROM . self::PRICE_JOIN . self::COVER_JOIN . self::VISIBLE_WHERE
            . ' AND p.is_featured = 1 ORDER BY p.sales_count DESC, p.id DESC LIMIT :limit',
            ['limit' => $limit]
        );
    }

    /** @return list<array<string, mixed>> */
    public function newest(int $limit): array
    {
        return $this->fetchAll(
            self::CARD_FIELDS . self::VISIBLE_FROM . self::PRICE_JOIN . self::COVER_JOIN . self::VISIBLE_WHERE
            . ' AND p.is_new = 1 ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC LIMIT :limit',
            ['limit' => $limit]
        );
    }

    /**
     * "Comprados juntos": produtos que aparecem nos mesmos pedidos pagos (não cancelados)
     * que os informados, do mais frequente para o menos.
     *
     * @param list<int> $productIds
     * @return list<int>
     */
    public function boughtTogether(array $productIds, int $limit): array
    {
        if ($productIds === []) {
            return [];
        }
        [$in, $params] = $this->inList($productIds, 'p');
        [$notIn, $notParams] = $this->inList($productIds, 'x');

        return array_map('intval', array_column($this->fetchAll(
            "SELECT other.product_id, COUNT(DISTINCT other.order_id) AS together
               FROM order_items base
               JOIN orders o ON o.id = base.order_id AND o.paid_at IS NOT NULL AND o.status <> 'cancelled'
               JOIN order_items other ON other.order_id = base.order_id
              WHERE base.product_id IN ({$in}) AND other.product_id NOT IN ({$notIn})
              GROUP BY other.product_id
              ORDER BY together DESC, other.product_id DESC
              LIMIT :limit",
            $params + $notParams + ['limit' => $limit]
        ), 'product_id'));
    }

    /**
     * Mais vendidos visíveis, fora os informados.
     *
     * @param list<int> $excludeIds
     * @return list<array<string, mixed>>
     */
    public function bestsellers(array $excludeIds, int $limit): array
    {
        [$notIn, $params] = $this->inList($excludeIds === [] ? [0] : $excludeIds, 'x');

        return $this->fetchAll(
            self::CARD_FIELDS . self::VISIBLE_FROM . self::PRICE_JOIN . self::COVER_JOIN . self::VISIBLE_WHERE
            . " AND p.id NOT IN ({$notIn}) AND pv.sellable_count > 0
              ORDER BY p.sales_count DESC, p.is_featured DESC, p.id DESC LIMIT :limit",
            $params + ['limit' => $limit]
        );
    }

    /**
     * Cartões dos produtos visíveis, na ordem dos ids informados.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function cardsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $params] = $this->inList($ids, 'i');
        $rows = $this->fetchAll(
            self::CARD_FIELDS . self::VISIBLE_FROM . self::PRICE_JOIN . self::COVER_JOIN . self::VISIBLE_WHERE . " AND p.id IN ({$in})",
            $params
        );
        $byId = array_column($rows, null, 'id');

        return array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    private function inList(array $ids, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $n => $id) {
            $placeholders[] = ":{$prefix}{$n}";
            $params["{$prefix}{$n}"] = (int) $id;
        }

        return [implode(', ', $placeholders), $params];
    }

    /** @return list<array{slug: string, updated_at: string, cover_path: ?string}> produtos visíveis para o sitemap */
    public function sitemapProducts(): array
    {
        return $this->fetchAll(
            'SELECT p.slug, GREATEST(p.updated_at, v.updated_at) AS updated_at, cover.path AS cover_path'
            . self::VISIBLE_FROM . self::COVER_JOIN . self::VISIBLE_WHERE . ' ORDER BY p.id'
        );
    }

    /** @return list<array<string, mixed>> */
    public function related(int $productId, int $categoryId, int $limit): array
    {
        return $this->fetchAll(
            self::CARD_FIELDS . self::VISIBLE_FROM . self::PRICE_JOIN . self::COVER_JOIN . self::VISIBLE_WHERE
            . ' AND p.category_id = :category_id AND p.id <> :id
              ORDER BY p.is_featured DESC, p.sales_count DESC, p.id DESC LIMIT :limit',
            ['category_id' => $categoryId, 'id' => $productId, 'limit' => $limit]
        );
    }

    /**
     * Produto visível com categoria, variante padrão e estoque.
     *
     * @return array<string, mixed>|null
     */
    public function findVisibleBySlug(string $slug): ?array
    {
        return $this->fetchOne(
            'SELECT p.id, p.category_id, p.name, p.slug, p.short_description, p.description, p.highlights,
                    p.production_lead_days, p.personalization_enabled, p.is_new, p.meta_title, p.meta_description,
                    c.name AS category_name, c.slug AS category_slug,
                    cp.name AS parent_category_name, cp.slug AS parent_category_slug,
                    v.id AS variant_id, v.sku, v.price_cents, v.compare_at_price_cents, v.material_label, v.finish_label,
                    v.width_mm, v.height_mm, v.depth_mm, v.weight_g,
                    COALESCE(i.stock_mode, \'made_to_order\') AS stock_mode,
                    GREATEST(COALESCE(i.quantity_on_hand, 0) - COALESCE(i.quantity_reserved, 0), 0) AS available'
            . self::VISIBLE_FROM . self::VISIBLE_WHERE . ' AND p.slug = :slug',
            ['slug' => $slug]
        );
    }

    /**
     * Variantes ativas de um produto (para escolha na página), padrão primeiro.
     *
     * @return list<array<string, mixed>>
     */
    public function variantsForProduct(int $productId): array
    {
        return $this->fetchAll(
            'SELECT v.id, v.sku, v.name, v.price_cents, v.compare_at_price_cents, v.material_label, v.finish_label,
                    v.width_mm, v.height_mm, v.depth_mm, v.weight_g, v.is_default,
                    COALESCE(i.stock_mode, \'made_to_order\') AS stock_mode,
                    GREATEST(COALESCE(i.quantity_on_hand, 0) - COALESCE(i.quantity_reserved, 0), 0) AS available
               FROM product_variants v
               LEFT JOIN inventory i ON i.variant_id = v.id
              WHERE v.product_id = :id AND v.is_active = 1 AND v.deleted_at IS NULL
              ORDER BY v.is_default DESC, v.sort_order, v.id',
            ['id' => $productId]
        );
    }

    /** @return list<array{path: string, alt_text: string}> */
    public function images(int $productId): array
    {
        return $this->fetchAll(
            'SELECT path, alt_text FROM product_images WHERE product_id = :id ORDER BY is_cover DESC, sort_order, id',
            ['id' => $productId]
        );
    }

    /**
     * Categorias visíveis na loja (ativas, com mãe ativa), com total de produtos visíveis.
     *
     * @return list<array<string, mixed>> id, parent_id, name, slug, description, meta_*, product_count
     */
    public function visibleCategories(): array
    {
        return $this->fetchAll(
            'SELECT cat.id, cat.parent_id, cat.name, cat.slug, cat.description, cat.meta_title, cat.meta_description,
                    (SELECT COUNT(*)' . self::VISIBLE_FROM . self::VISIBLE_WHERE . ' AND p.category_id = cat.id) AS product_count
               FROM categories cat
               LEFT JOIN categories parent ON parent.id = cat.parent_id
              WHERE cat.is_active = 1 AND cat.deleted_at IS NULL
                AND (cat.parent_id IS NULL OR (parent.is_active = 1 AND parent.deleted_at IS NULL))
              ORDER BY cat.sort_order, cat.name'
        );
    }

    /**
     * Linha de um produto para o carrinho: preço e estoque atuais da variante,
     * apenas se o produto ainda estiver visível na loja.
     *
     * @param list<int> $variantIds
     * @return array<int, array<string, mixed>> indexado pelo id da variante
     */
    public function variantsForCart(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values($variantIds) as $n => $id) {
            $placeholders[] = ':v' . $n;
            $params['v' . $n] = $id;
        }

        $rows = $this->fetchAll(
            'SELECT v.id AS variant_id, v.sku, v.name AS variant_name, v.price_cents, v.compare_at_price_cents,
                    v.weight_g, v.package_weight_g,
                    p.id AS product_id, p.name, p.slug, p.production_lead_days,
                    COALESCE(i.stock_mode, \'made_to_order\') AS stock_mode,
                    GREATEST(COALESCE(i.quantity_on_hand, 0) - COALESCE(i.quantity_reserved, 0), 0) AS available,
                    cover.path AS cover_path, cover.alt_text AS cover_alt
               FROM product_variants v
               JOIN products p ON p.id = v.product_id
               JOIN categories c ON c.id = p.category_id AND c.is_active = 1 AND c.deleted_at IS NULL
               LEFT JOIN categories cp ON cp.id = c.parent_id
               LEFT JOIN inventory i ON i.variant_id = v.id'
            . self::COVER_JOIN
            . self::VISIBLE_WHERE . ' AND v.is_active = 1 AND v.deleted_at IS NULL
                AND v.id IN (' . implode(', ', $placeholders) . ')',
            $params
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['variant_id']] = $row;
        }

        return $byId;
    }
}
