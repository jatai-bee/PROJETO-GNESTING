<?php

declare(strict_types=1);

namespace GNesting\Services;

/**
 * Dados estruturados (schema.org, JSON-LD) para buscadores: produto com oferta,
 * trilha de navegação e a loja. Só dados já públicos na página.
 */
final class SeoData
{
    /**
     * @param array<string, mixed>       $product
     * @param list<array<string, mixed>> $variants ativas (com in_stock)
     * @param list<array{path: string, alt_text: string}> $images
     * @return array<string, mixed>
     */
    public static function product(array $product, array $variants, array $images, string $canonical): array
    {
        $offers = array_map(static fn (array $v): array => [
            '@type' => 'Offer',
            'sku' => (string) $v['sku'],
            'price' => sprintf('%d.%02d', intdiv((int) $v['price_cents'], 100), (int) $v['price_cents'] % 100),
            'priceCurrency' => 'BRL',
            'availability' => $v['in_stock'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url' => $canonical . (count($variants) > 1 ? '?variante=' . $v['id'] : ''),
            'itemCondition' => 'https://schema.org/NewCondition',
        ], $variants);

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => (string) $product['name'],
            'description' => (string) ($product['short_description'] ?: $product['meta_description'] ?: ''),
            'sku' => (string) $product['sku'],
            'category' => (string) $product['category_name'],
            'brand' => ['@type' => 'Brand', 'name' => 'G-Nesting'],
            'image' => array_map(static fn (array $i): string => absolute_upload_url($i['path'], 1600), $images),
            'material' => $product['material_label'] ?? null,
            'offers' => count($offers) === 1 ? $offers[0] : $offers,
            'url' => $canonical,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * @param list<array{label: string, url: ?string}> $breadcrumbs
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $breadcrumbs): array
    {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => absolute_url('/')]];
        foreach ($breadcrumbs as $index => $crumb) {
            $items[] = array_filter([
                '@type' => 'ListItem',
                'position' => $index + 2,
                'name' => $crumb['label'],
                // url() já inclui o caminho base; absolute_url recebe o caminho sem ele
                'item' => $crumb['url'] === null ? null : self::absolute((string) $crumb['url']),
            ], static fn ($v) => $v !== null);
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** @return array<string, mixed> */
    public static function store(string $contactEmail): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'G-Nesting',
            'slogan' => (string) config('app.tagline'),
            'url' => absolute_url('/'),
            'logo' => absolute_url('/assets/img/logo.svg'),
            'email' => $contactEmail ?: null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * JSON seguro para <script type="application/ld+json"> (não fecha a tag nem injeta HTML).
     *
     * @param array<string, mixed>|list<mixed> $data
     */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    private static function absolute(string $relativeUrl): string
    {
        $base = (string) config('app.base_path', '');
        $path = $base !== '' && str_starts_with($relativeUrl, $base) ? substr($relativeUrl, strlen($base)) : $relativeUrl;

        return absolute_url($path);
    }
}
