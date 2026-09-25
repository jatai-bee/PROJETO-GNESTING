<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\CartContext;
use GNesting\Core\Config;
use GNesting\Repositories\CartRepository;
use GNesting\Repositories\CatalogRepository;

/**
 * Carrinho do visitante (identificado pelo cookie — CartContext).
 *
 * Regras:
 * - preço e disponibilidade são sempre lidos na hora (nunca confiados ao carrinho);
 * - produto com estoque próprio ('stock') não passa do disponível; sob encomenda
 *   ('made_to_order') vai até o limite por linha;
 * - produto que saiu da loja continua listado, marcado como indisponível e fora do total;
 * - personalização obrigatória só é aceita a partir da etapa 5.
 */
final class CartService
{
    private ?int $cartId = null;
    private ?string $resolvedFor = null;

    public function __construct(
        private readonly CartRepository $carts,
        private readonly CatalogRepository $catalog,
        private readonly CartContext $context,
        private readonly Config $config,
    ) {
    }

    /**
     * Itens com preço atual e total.
     *
     * @return array{items: list<array<string, mixed>>, subtotal_cents: int, quantity: int, lead_days: int, has_issues: bool}
     */
    public function summary(): array
    {
        $empty = ['items' => [], 'subtotal_cents' => 0, 'quantity' => 0, 'lead_days' => 0, 'has_issues' => false];
        $cartId = $this->currentCartId();
        if ($cartId === null) {
            return $empty;
        }

        $rows = $this->carts->items($cartId);
        $variants = $this->catalog->variantsForCart(array_map(static fn (array $r): int => (int) $r['variant_id'], $rows));

        $summary = $empty;
        foreach ($rows as $row) {
            $quantity = (int) $row['quantity'];
            $variant = $variants[(int) $row['variant_id']] ?? null;

            if ($variant === null) {
                $summary['items'][] = ['id' => (int) $row['id'], 'quantity' => $quantity, 'issue' => 'unavailable'];
                $summary['has_issues'] = true;
                continue;
            }

            $limit = $this->limitFor($variant);
            $issue = match (true) {
                (bool) $variant['requires_personalization'] => 'unavailable',
                $limit === 0 => 'out_of_stock',
                $quantity > $limit => 'insufficient_stock',
                default => null,
            };
            $unit = (int) $variant['price_cents'];

            $summary['items'][] = [
                'id' => (int) $row['id'],
                'quantity' => $quantity,
                'issue' => $issue,
                'max_quantity' => $limit,
                'unit_price_cents' => $unit,
                'compare_at_price_cents' => $variant['compare_at_price_cents'] === null ? null : (int) $variant['compare_at_price_cents'],
                'line_total_cents' => $unit * $quantity,
            ] + $variant;

            if ($issue === null) {
                $summary['subtotal_cents'] += $unit * $quantity;
                $summary['quantity'] += $quantity;
                $summary['lead_days'] = max($summary['lead_days'], (int) $variant['production_lead_days']);
            } else {
                $summary['has_issues'] = true;
            }
        }

        return $summary;
    }

    /** Total de unidades (ícone do carrinho no cabeçalho). */
    public function itemCount(): int
    {
        $cartId = $this->currentCartId();

        return $cartId === null ? 0 : $this->carts->itemQuantity($cartId);
    }

    /**
     * Adiciona a variante (soma à linha existente).
     *
     * @throws BusinessRuleException
     */
    public function add(int $variantId, int $quantity, ?int $customerId = null): void
    {
        $this->assertQuantity($quantity);

        $variant = $this->catalog->variantsForCart([$variantId])[$variantId] ?? null;
        if ($variant === null) {
            throw new BusinessRuleException('Este produto não está disponível no momento.');
        }
        if ((bool) $variant['requires_personalization']) {
            throw new BusinessRuleException('Este produto exige personalização, disponível em breve na loja.');
        }

        $cartId = $this->currentCartId() ?? $this->createCart($customerId);
        $line = $this->carts->findLine($cartId, $variantId);
        $newQuantity = ($line === null ? 0 : (int) $line['quantity']) + $quantity;
        $this->assertStock($variant, $newQuantity);

        if ($line === null) {
            if ($this->carts->countLines($cartId) >= (int) $this->config->get('cart.max_lines', 30)) {
                throw new BusinessRuleException('Seu carrinho atingiu o limite de itens diferentes.');
            }
            $this->carts->addItem($cartId, $variantId, $quantity);
        } else {
            $this->carts->setQuantity((int) $line['id'], $newQuantity);
        }

        $this->carts->touch($cartId, $customerId, $this->lifetimeDays());
    }

    /**
     * Altera a quantidade de um item. Quantidade 0 remove.
     *
     * @throws BusinessRuleException
     */
    public function update(int $itemId, int $quantity): void
    {
        if ($quantity === 0) {
            $this->remove($itemId);

            return;
        }
        $this->assertQuantity($quantity);

        $cartId = $this->currentCartId();
        $item = $cartId === null ? null : $this->carts->findItem($cartId, $itemId);
        if ($item === null) {
            throw new BusinessRuleException('Item não encontrado no seu carrinho.');
        }

        $variantId = (int) $item['variant_id'];
        $variant = $this->catalog->variantsForCart([$variantId])[$variantId] ?? null;
        if ($variant === null) {
            throw new BusinessRuleException('Este produto não está mais disponível. Remova-o do carrinho.');
        }
        $this->assertStock($variant, $quantity);

        $this->carts->setQuantity($itemId, $quantity);
        $this->carts->touch($cartId, null, $this->lifetimeDays());
    }

    public function remove(int $itemId): void
    {
        $cartId = $this->currentCartId();
        if ($cartId !== null) {
            $this->carts->removeItem($cartId, $itemId);
        }
    }

    /** Limite da linha: disponível em estoque ou o máximo por linha (sob encomenda). */
    private function limitFor(array $variant): int
    {
        $max = (int) $this->config->get('cart.max_quantity', 99);

        return $variant['stock_mode'] === 'stock' ? min($max, (int) $variant['available']) : $max;
    }

    private function assertQuantity(int $quantity): void
    {
        $max = (int) $this->config->get('cart.max_quantity', 99);
        if ($quantity < 1 || $quantity > $max) {
            throw new BusinessRuleException("Escolha uma quantidade entre 1 e {$max}.");
        }
    }

    /** @param array<string, mixed> $variant */
    private function assertStock(array $variant, int $quantity): void
    {
        $limit = $this->limitFor($variant);
        if ($limit === 0) {
            throw new BusinessRuleException('Este produto está esgotado no momento.');
        }
        if ($quantity > $limit) {
            throw new BusinessRuleException($variant['stock_mode'] === 'stock'
                ? "Temos apenas {$limit} unidade(s) disponível(is) deste produto."
                : "A quantidade máxima por item é {$limit}.");
        }
    }

    private function currentCartId(): ?int
    {
        $token = $this->context->token();
        if ($token === null) {
            return null;
        }
        if ($this->resolvedFor !== $token) {
            $cart = $this->carts->findActiveByTokenHash(hash('sha256', $token));
            $this->cartId = $cart === null ? null : (int) $cart['id'];
            $this->resolvedFor = $token;
        }

        return $this->cartId;
    }

    private function createCart(?int $customerId): int
    {
        $token = bin2hex(random_bytes(32));
        $this->cartId = $this->carts->create(hash('sha256', $token), $customerId, $this->lifetimeDays());
        $this->resolvedFor = $token;
        $this->context->issue($token);

        return $this->cartId;
    }

    private function lifetimeDays(): int
    {
        return (int) $this->config->get('cart.lifetime_days', 30);
    }
}
