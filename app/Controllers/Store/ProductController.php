<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Session;
use GNesting\Repositories\CatalogRepository;
use GNesting\Repositories\ProductOptionRepository;
use GNesting\Services\PersonalizationService;
use GNesting\Services\RelatedProducts;
use GNesting\Services\SeoData;
use GNesting\Services\WhatsApp;

/** Página de produto: variações pré-cadastradas e personalização controlada. */
final class ProductController extends Controller
{
    private const RELATED = 4;

    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly PersonalizationService $personalization,
        private readonly ProductOptionRepository $options,
        private readonly Session $session,
        private readonly RelatedProducts $related,
        private readonly WhatsApp $whatsapp,
    ) {
    }

    public function show(Request $request): Response
    {
        $product = $this->catalog->findVisibleBySlug((string) $request->param('slug'));
        if ($product === null) {
            throw HttpException::notFound();
        }

        $variants = $this->catalog->variantsForProduct((int) $product['id']);
        $maxPerLine = (int) config('cart.max_quantity', 99);
        foreach ($variants as &$variant) {
            $variant['in_stock'] = $variant['stock_mode'] !== 'stock' || (int) $variant['available'] > 0;
            $variant['max_quantity'] = $variant['stock_mode'] === 'stock' ? min($maxPerLine, (int) $variant['available']) : $maxPerLine;
        }
        unset($variant);

        // Variação exibida: a pedida na URL, a da última tentativa (erro) ou a padrão
        $old = $this->session->getFlash('old', []);
        $wanted = $request->queryInt('variante') ?: (int) ($old['variant_id'] ?? 0);
        $selected = $variants[0];
        foreach ($variants as $variant) {
            if ((int) $variant['id'] === $wanted) {
                $selected = $variant;
            }
        }

        $breadcrumbs = [['label' => 'Produtos', 'url' => url('/produtos')]];
        if ($product['parent_category_slug'] !== null) {
            $breadcrumbs[] = ['label' => $product['parent_category_name'], 'url' => url('/categoria/' . $product['parent_category_slug'])];
        }
        $breadcrumbs[] = ['label' => $product['category_name'], 'url' => url('/categoria/' . $product['category_slug'])];
        $breadcrumbs[] = ['label' => $product['name'], 'url' => null];

        $highlights = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $product['highlights']) ?: [])));
        $images = $this->catalog->images((int) $product['id']);
        $canonical = absolute_url('/produto/' . $product['slug']);

        return $this->render('store/product/show', [
            'ogType' => 'product',
            'ogImage' => $images === [] ? null : absolute_upload_url($images[0]['path'], 800),
            'jsonLd' => [
                SeoData::product($product, $variants, $images, $canonical),
                SeoData::breadcrumbs($breadcrumbs),
            ],
            'whatsappUrl' => $this->whatsapp->forProduct((string) $product['name'], $canonical),
            'title' => ($product['meta_title'] ?: $product['name'] . ' | G-Nesting'),
            'metaDescription' => $product['meta_description'] ?: $product['short_description'],
            'canonical' => $canonical,
            'product' => $product,
            'variants' => $variants,
            'variantLabel' => implode(' / ', array_column($this->options->optionsWithValues((int) $product['id']), 'name')) ?: 'Versão',
            'selected' => $selected,
            'anyInStock' => in_array(true, array_column($variants, 'in_stock'), true),
            'rules' => $this->personalization->rulesForProduct((int) $product['id']),
            'images' => $images,
            'highlights' => $highlights,
            'related' => $this->related->forProduct((int) $product['id'], (int) $product['category_id'], self::RELATED),
            'breadcrumbs' => $breadcrumbs,
        ]);
    }
}
