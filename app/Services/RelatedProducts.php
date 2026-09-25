<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Repositories\CatalogRepository;

/**
 * Produtos relacionados, sem cadastro manual:
 * 1. "comprados juntos" (mesmos pedidos pagos);
 * 2. mesma categoria (destaques e mais vendidos primeiro);
 * 3. mais vendidos da loja.
 * Só aparecem produtos visíveis e com algo disponível para venda.
 */
final class RelatedProducts
{
    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forProduct(int $productId, int $categoryId, int $limit = 4): array
    {
        $cards = $this->catalog->cardsByIds($this->catalog->boughtTogether([$productId], $limit));
        $cards = $this->merge($cards, $this->catalog->related($productId, $categoryId, $limit), $limit, [$productId]);

        return $this->merge($cards, $this->catalog->bestsellers([$productId], $limit), $limit, [$productId]);
    }

    /**
     * Sugestões para o carrinho (complementos do que já está nele).
     *
     * @param list<int> $cartProductIds
     * @return list<array<string, mixed>>
     */
    public function forCart(array $cartProductIds, int $limit = 4): array
    {
        if ($cartProductIds === []) {
            return [];
        }
        $cards = $this->catalog->cardsByIds($this->catalog->boughtTogether($cartProductIds, $limit));

        return $this->merge($cards, $this->catalog->bestsellers($cartProductIds, $limit), $limit, $cartProductIds);
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @param list<array<string, mixed>> $more
     * @param list<int> $exclude
     * @return list<array<string, mixed>>
     */
    private function merge(array $cards, array $more, int $limit, array $exclude): array
    {
        $cards = array_values(array_filter($cards, static fn (array $c): bool => (int) $c['sellable_count'] > 0));
        $seen = array_merge($exclude, array_map(static fn (array $c): int => (int) $c['id'], $cards));
        foreach ($more as $card) {
            if (count($cards) >= $limit) {
                break;
            }
            if (!in_array((int) $card['id'], $seen, true) && (int) $card['sellable_count'] > 0) {
                $cards[] = $card;
                $seen[] = (int) $card['id'];
            }
        }

        return array_slice($cards, 0, $limit);
    }
}
