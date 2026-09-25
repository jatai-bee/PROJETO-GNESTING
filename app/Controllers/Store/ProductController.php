<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\CatalogRepository;

/** Página de produto. */
final class ProductController extends Controller
{
    private const RELATED = 4;

    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    public function show(Request $request): Response
    {
        $product = $this->catalog->findVisibleBySlug((string) $request->param('slug'));
        if ($product === null) {
            throw HttpException::notFound();
        }

        $breadcrumbs = [['label' => 'Produtos', 'url' => url('/produtos')]];
        if ($product['parent_category_slug'] !== null) {
            $breadcrumbs[] = ['label' => $product['parent_category_name'], 'url' => url('/categoria/' . $product['parent_category_slug'])];
        }
        $breadcrumbs[] = ['label' => $product['category_name'], 'url' => url('/categoria/' . $product['category_slug'])];
        $breadcrumbs[] = ['label' => $product['name'], 'url' => null];

        $highlights = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $product['highlights']) ?: [])));
        $inStock = $product['stock_mode'] !== 'stock' || (int) $product['available'] > 0;

        return $this->render('store/product/show', [
            'title' => ($product['meta_title'] ?: $product['name'] . ' | G-Nesting'),
            'metaDescription' => $product['meta_description'] ?: $product['short_description'],
            'canonical' => absolute_url('/produto/' . $product['slug']),
            'product' => $product,
            'images' => $this->catalog->images((int) $product['id']),
            'highlights' => $highlights,
            'inStock' => $inStock,
            'maxQuantity' => $product['stock_mode'] === 'stock'
                ? min((int) config('cart.max_quantity', 99), (int) $product['available'])
                : (int) config('cart.max_quantity', 99),
            'related' => $this->catalog->related((int) $product['id'], (int) $product['category_id'], self::RELATED),
            'breadcrumbs' => $breadcrumbs,
        ]);
    }
}
