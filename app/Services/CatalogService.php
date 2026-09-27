<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Paginator;
use GNesting\Repositories\CatalogRepository;

/**
 * Vitrine: árvore de categorias e listagem com filtros, busca, ordenação e paginação.
 */
final class CatalogService
{
    public const PER_PAGE = 12;
    private const MAX_TERMS = 5;

    /** @var list<array<string, mixed>>|null */
    private ?array $categories = null;

    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /**
     * Categorias visíveis em árvore (mães com filhas), para o menu e os filtros.
     *
     * @return list<array<string, mixed>> cada item com 'children'
     */
    public function categoryTree(): array
    {
        $all = $this->categories();
        $tree = [];
        foreach ($all as $category) {
            if ($category['parent_id'] === null) {
                $category['children'] = array_values(array_filter(
                    $all,
                    static fn (array $c): bool => (int) $c['parent_id'] === (int) $category['id']
                ));
                $category['total'] = (int) $category['product_count']
                    + array_sum(array_map(static fn (array $c): int => (int) $c['product_count'], $category['children']));
                $tree[] = $category;
            }
        }

        return $tree;
    }

    /** @return array<string, mixed>|null categoria visível, com 'parent' e 'children' */
    public function findCategory(string $slug): ?array
    {
        $all = $this->categories();
        foreach ($all as $category) {
            if ($category['slug'] !== $slug) {
                continue;
            }
            $category['children'] = array_values(array_filter(
                $all,
                static fn (array $c): bool => (int) $c['parent_id'] === (int) $category['id']
            ));
            $category['parent'] = null;
            foreach ($all as $candidate) {
                if ($category['parent_id'] !== null && (int) $candidate['id'] === (int) $category['parent_id']) {
                    $category['parent'] = $candidate;
                }
            }

            return $category;
        }

        return null;
    }

    /**
     * Normaliza os filtros vindos da URL.
     *
     * @param array{q?: string, min?: string, max?: string, ordem?: string} $input
     * @return array{q: string, terms: list<string>, min_cents: ?int, max_cents: ?int, sort: string}
     */
    public function normalizeFilters(array $input): array
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', $input['q'] ?? ''));
        $terms = array_values(array_unique(array_filter(
            explode(' ', mb_strtolower($q, 'UTF-8')),
            static fn (string $t): bool => mb_strlen($t) >= 2
        )));

        $min = parse_money($input['min'] ?? '');
        $max = parse_money($input['max'] ?? '');
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $sort = in_array($input['ordem'] ?? '', CatalogRepository::SORTS, true) ? $input['ordem'] : 'relevancia';

        $flags = array_values(array_filter(
            array_keys(CatalogRepository::FLAGS),
            static fn (string $flag): bool => ($input[$flag] ?? '') === '1'
        ));

        return [
            'q' => $q,
            'terms' => array_slice($terms, 0, self::MAX_TERMS),
            'min_cents' => $min === 0 ? null : $min,
            'max_cents' => $max,
            'sort' => $sort,
            'flags' => $flags,
        ];
    }

    /**
     * @param array{terms: list<string>, min_cents: ?int, max_cents: ?int, sort: string} $filters
     * @param list<int> $categoryIds vazio = todas
     * @return array{products: list<array<string, mixed>>, paginator: Paginator}
     */
    public function listing(array $filters, array $categoryIds, int $page): array
    {
        $criteria = [
            'category_ids' => $categoryIds,
            'terms' => $filters['terms'],
            'min_cents' => $filters['min_cents'],
            'max_cents' => $filters['max_cents'],
            'flags' => $filters['flags'] ?? [],
        ];
        $paginator = new Paginator($this->catalog->count($criteria), $page, self::PER_PAGE);

        return [
            'products' => $this->catalog->paginate($criteria, $filters['sort'], $paginator->perPage, $paginator->offset()),
            'paginator' => $paginator,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function categories(): array
    {
        return $this->categories ??= $this->catalog->visibleCategories();
    }
}
