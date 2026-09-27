<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\CatalogRepository;
use GNesting\Services\Auth\TooManyAttemptsException;
use GNesting\Services\CatalogService;
use GNesting\Services\RateLimiter;

/** Listagens da loja: todos os produtos, categoria e busca. */
final class CatalogController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->listing($request, '/produtos', [], [
            'title' => 'Todos os produtos | G-Nesting',
            'metaDescription' => 'Objetos de design produzidos com fabricação digital: relógios, painéis, organizadores e presentes.',
            'heading' => 'Todos os produtos',
            'intro' => 'Objetos de design recortados com precisão e acabados à mão.',
            'breadcrumbs' => [['label' => 'Produtos', 'url' => null]],
        ]);
    }

    public function category(Request $request): Response
    {
        $category = $this->catalog->findCategory((string) $request->param('slug'));
        if ($category === null) {
            throw HttpException::notFound();
        }

        $ids = [(int) $category['id'], ...array_map(static fn (array $c): int => (int) $c['id'], $category['children'])];
        $breadcrumbs = [['label' => 'Produtos', 'url' => url('/produtos')]];
        if ($category['parent'] !== null) {
            $breadcrumbs[] = ['label' => $category['parent']['name'], 'url' => url('/categoria/' . $category['parent']['slug'])];
        }
        $breadcrumbs[] = ['label' => $category['name'], 'url' => null];

        return $this->listing($request, '/categoria/' . $category['slug'], $ids, [
            'title' => ($category['meta_title'] ?? null) ?: $category['name'] . ' | G-Nesting',
            'metaDescription' => ($category['meta_description'] ?? null) ?: $category['description'],
            'heading' => $category['name'],
            'intro' => $category['description'],
            'breadcrumbs' => $breadcrumbs,
            'activeCategory' => $category,
        ]);
    }

    public function search(Request $request): Response
    {
        $key = 'search:' . $request->ip();
        [$max, $window] = config('security.rate_limits.search', [60, 60]);
        try {
            $this->rateLimiter->ensureNotBlocked($key);
        } catch (TooManyAttemptsException $e) {
            throw HttpException::tooManyRequests($e->retryAfterSeconds());
        }
        $this->rateLimiter->hit($key, (int) $max, (int) $window);

        $q = $request->queryString('q', 80);

        return $this->listing($request, '/busca', [], [
            'title' => ($q === '' ? 'Buscar' : "Busca: {$q}") . ' | G-Nesting',
            'heading' => $q === '' ? 'Buscar produtos' : "Resultados para “{$q}”",
            'intro' => null,
            'breadcrumbs' => [['label' => 'Busca', 'url' => null]],
            'isSearch' => true,
            'noindex' => true,
        ]);
    }

    /**
     * @param list<int>            $categoryIds
     * @param array<string, mixed> $page
     */
    private function listing(Request $request, string $path, array $categoryIds, array $page): Response
    {
        $filters = $this->catalog->normalizeFilters([
            'q' => $request->queryString('q', 80),
            'min' => $request->queryString('min', 15),
            'max' => $request->queryString('max', 15),
            'ordem' => $request->queryString('ordem', 20),
        ] + array_map(static fn (string $flag): string => $request->queryString($flag, 1), array_combine(
            array_keys(CatalogRepository::FLAGS), array_keys(CatalogRepository::FLAGS)
        )));
        $isSearch = $page['isSearch'] ?? false;
        if (!$isSearch) {
            $filters['q'] = '';
            $filters['terms'] = [];
        }

        // Busca sem termo válido não lista o catálogo inteiro
        $result = $isSearch && $filters['terms'] === []
            ? null
            : $this->catalog->listing($filters, $categoryIds, $request->queryInt('pagina', 1));

        $params = [
            'q' => $isSearch ? $filters['q'] : null,
            'min' => $filters['min_cents'] === null ? null : money_input($filters['min_cents']),
            'max' => $filters['max_cents'] === null ? null : money_input($filters['max_cents']),
            'ordem' => $filters['sort'] === 'relevancia' ? null : $filters['sort'],
        ];
        foreach (array_keys(CatalogRepository::FLAGS) as $flag) {
            $params[$flag] = in_array($flag, $filters['flags'], true) ? '1' : null;
        }

        return $this->render('store/catalog/index', $page + [
            'products' => $result['products'] ?? [],
            'paginator' => $result['paginator'] ?? null,
            'filters' => $filters,
            'params' => $params,
            'path' => $path,
            'canonical' => absolute_url($path),
            'categoryTree' => $this->catalog->categoryTree(),
            'activeCategory' => null,
            'isSearch' => false,
            'noindex' => false,
            // Com filtros aplicados a página não deve ser indexada (canônica = URL limpa)
            'hasFilters' => $params['min'] !== null || $params['max'] !== null || $params['ordem'] !== null || $filters['flags'] !== [],
        ]);
    }
}
