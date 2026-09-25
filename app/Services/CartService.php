<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\CartContext;
use GNesting\Core\Config;
use GNesting\Core\ValidationException;
use GNesting\Repositories\CartRepository;
use GNesting\Repositories\CouponRepository;
use GNesting\Repositories\CatalogRepository;

/**
 * Carrinho do visitante (identificado pelo cookie — CartContext).
 *
 * Regras:
 * - preço, acréscimos de personalização e disponibilidade são sempre lidos na hora;
 *   preço unitário = preço da variante + acréscimos das regras/opções atuais;
 * - a mesma variante com personalizações diferentes gera linhas diferentes (personalization_hash);
 * - produto com estoque próprio ('stock') não passa do disponível, somando todas as linhas
 *   da variante; sob encomenda ('made_to_order') vai até o limite por linha;
 * - itens que deixaram de ser válidos (produto fora da loja, estoque insuficiente,
 *   personalização que não atende mais às regras) continuam listados, marcados e fora do total.
 */
final class CartService
{
    private ?int $cartId = null;
    private ?string $resolvedFor = null;

    public function __construct(
        private readonly CartRepository $carts,
        private readonly CatalogRepository $catalog,
        private readonly PersonalizationService $personalization,
        private readonly CartContext $context,
        private readonly Config $config,
        private readonly CouponService $coupons,
        private readonly CouponRepository $couponRepository,
    ) {
    }

    /**
     * Itens com preço atual e total.
     *
     * @return array{items: list<array<string, mixed>>, subtotal_cents: int, quantity: int, lead_days: int, has_issues: bool,
     *               coupon: ?array<string, mixed>, discount_cents: int, total_cents: int}
     */
    public function summary(): array
    {
        $empty = ['items' => [], 'subtotal_cents' => 0, 'quantity' => 0, 'lead_days' => 0, 'has_issues' => false,
            'coupon' => null, 'discount_cents' => 0, 'total_cents' => 0];
        $cartId = $this->currentCartId();
        if ($cartId === null) {
            return $empty;
        }

        $rows = $this->carts->items($cartId);
        $variants = $this->catalog->variantsForCart(array_map(static fn (array $r): int => (int) $r['variant_id'], $rows));
        $stored = $this->carts->personalizations($cartId);

        $perVariant = [];
        foreach ($rows as $row) {
            $perVariant[(int) $row['variant_id']] = ($perVariant[(int) $row['variant_id']] ?? 0) + (int) $row['quantity'];
        }

        $summary = $empty;
        foreach ($rows as $row) {
            $quantity = (int) $row['quantity'];
            $variant = $variants[(int) $row['variant_id']] ?? null;

            if ($variant === null) {
                $summary['items'][] = ['id' => (int) $row['id'], 'quantity' => $quantity, 'issue' => 'unavailable', 'personalization' => []];
                $summary['has_issues'] = true;
                continue;
            }

            try {
                $chosen = $stored[(int) $row['id']] ?? [];
                $personalization = $this->personalization->validate((int) $variant['product_id'], $chosen);
                // Regra desativada depois da escolha: o valor deixaria de ser produzido — não pode sumir em silêncio
                $personalizationValid = count($personalization['items']) === count($chosen);
            } catch (ValidationException) {
                $personalization = ['items' => [], 'price_delta_cents' => 0];
                $personalizationValid = false;
            }

            $limit = $this->limitFor($variant);
            $issue = match (true) {
                !$personalizationValid => 'personalization_invalid',
                $limit === 0 => 'out_of_stock',
                $perVariant[(int) $row['variant_id']] > $limit => 'insufficient_stock',
                default => null,
            };
            $unit = (int) $variant['price_cents'] + (int) $personalization['price_delta_cents'];

            $summary['items'][] = [
                'id' => (int) $row['id'],
                'quantity' => $quantity,
                'issue' => $issue,
                'max_quantity' => max(1, $limit - ($perVariant[(int) $row['variant_id']] - $quantity)),
                'base_price_cents' => (int) $variant['price_cents'],
                'personalization_cents' => (int) $personalization['price_delta_cents'],
                'unit_price_cents' => $unit,
                'line_total_cents' => $unit * $quantity,
                'personalization' => $personalization['items'],
            ] + $variant;

            if ($issue === null) {
                $summary['subtotal_cents'] += $unit * $quantity;
                $summary['quantity'] += $quantity;
                $summary['lead_days'] = max($summary['lead_days'], (int) $variant['production_lead_days']);
            } else {
                $summary['has_issues'] = true;
            }
        }

        return $this->withCoupon($summary, $cartId);
    }

    /**
     * Cupom do carrinho, revalidado a cada leitura (subtotal ou validade podem ter mudado).
     * Cupom que deixou de valer continua visível com o motivo, sem desconto.
     *
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function withCoupon(array $summary, int $cartId): array
    {
        $summary += ['coupon' => null, 'discount_cents' => 0];
        $couponId = $this->carts->couponId($cartId);
        $coupon = $couponId === null ? null : $this->couponRepository->find($couponId);
        if ($coupon !== null) {
            try {
                $this->coupons->assertUsable($coupon, (int) $summary['subtotal_cents']);
                $discount = $this->coupons->discount($coupon, (int) $summary['subtotal_cents']);
                $summary['coupon'] = ['id' => (int) $coupon['id'], 'code' => $coupon['code'], 'label' => CouponService::describe($coupon),
                    'free_shipping' => $coupon['type'] === 'free_shipping', 'error' => null];
                $summary['discount_cents'] = $discount['items'];
            } catch (BusinessRuleException $e) {
                $summary['coupon'] = ['id' => (int) $coupon['id'], 'code' => $coupon['code'], 'label' => '', 'free_shipping' => false, 'error' => $e->getMessage()];
            }
        }
        $summary['total_cents'] = (int) $summary['subtotal_cents'] - (int) $summary['discount_cents'];

        return $summary;
    }

    /** @throws BusinessRuleException */
    public function applyCoupon(string $code): string
    {
        $cartId = $this->currentCartId() ?? throw new BusinessRuleException('Adicione produtos ao carrinho antes de usar um cupom.');
        $summary = $this->summary();
        $coupon = $this->coupons->findUsable($code, (int) $summary['subtotal_cents']);
        $this->carts->setCoupon($cartId, (int) $coupon['id']);

        return CouponService::describe($coupon);
    }

    public function removeCoupon(): void
    {
        $cartId = $this->currentCartId();
        if ($cartId !== null) {
            $this->carts->setCoupon($cartId, null);
        }
    }

    /** Total de unidades (ícone do carrinho no cabeçalho). */
    public function itemCount(): int
    {
        $cartId = $this->currentCartId();

        return $cartId === null ? 0 : $this->carts->itemQuantity($cartId);
    }

    /**
     * Adiciona a variante com a personalização informada (soma à linha idêntica, se houver).
     *
     * @param array<int, string> $personalizationInput rule_id => valor bruto
     * @throws BusinessRuleException
     * @throws ValidationException personalização inválida (chaves "pers_{rule_id}")
     */
    public function add(int $variantId, int $quantity, array $personalizationInput = [], ?int $customerId = null): void
    {
        $this->assertQuantity($quantity);

        $variant = $this->catalog->variantsForCart([$variantId])[$variantId] ?? null;
        if ($variant === null) {
            throw new BusinessRuleException('Este produto não está disponível no momento.');
        }
        // Valida antes de criar carrinho: erro não deixa rastro
        $personalization = $this->personalization->validate((int) $variant['product_id'], $personalizationInput);

        $cartId = $this->currentCartId();
        $inCart = $cartId === null ? 0 : $this->carts->variantQuantity($cartId, $variantId);
        $line = $cartId === null ? null : $this->carts->findLine($cartId, $variantId, $personalization['hash']);
        $this->assertStock($variant, $inCart + $quantity, ($line === null ? 0 : (int) $line['quantity']) + $quantity);

        $cartId ??= $this->createCart($customerId);
        if ($line === null) {
            if ($this->carts->countLines($cartId) >= (int) $this->config->get('cart.max_lines', 30)) {
                throw new BusinessRuleException('Seu carrinho atingiu o limite de itens diferentes.');
            }
            $itemId = $this->carts->addItem($cartId, $variantId, $quantity, $personalization['hash']);
            foreach ($personalization['items'] as $item) {
                $this->carts->addPersonalization($itemId, $item['rule_id'], $item['value_id'], $item['value_text']);
            }
        } else {
            $this->carts->setQuantity((int) $line['id'], (int) $line['quantity'] + $quantity);
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
        $otherLines = $this->carts->variantQuantity($cartId, $variantId) - (int) $item['quantity'];
        $this->assertStock($variant, $otherLines + $quantity, $quantity);

        $this->carts->setQuantity($itemId, $quantity);
        $this->carts->touch($cartId, null, $this->lifetimeDays());
    }

    /** Pedido criado: o carrinho é encerrado (a próxima compra começa um novo). */
    public function convert(): void
    {
        $cartId = $this->currentCartId();
        if ($cartId !== null) {
            $this->carts->markConverted($cartId);
            $this->cartId = null;
        }
    }

    /**
     * Cliente entrou na conta: o carrinho atual passa a ser dele; se não houver carrinho
     * neste navegador, retoma o carrinho ativo mais recente da conta (com token novo —
     * o antigo nunca é conhecido, só o hash).
     */
    public function adoptForCustomer(int $customerId): void
    {
        $cartId = $this->currentCartId();
        if ($cartId !== null) {
            $this->carts->touch($cartId, $customerId, $this->lifetimeDays());

            return;
        }
        $saved = $this->carts->latestActiveForCustomer($customerId);
        if ($saved === null) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $this->carts->replaceToken((int) $saved['id'], hash('sha256', $token));
        $this->cartId = (int) $saved['id'];
        $this->resolvedFor = $token;
        $this->context->issue($token);
    }

    public function remove(int $itemId): void
    {
        $cartId = $this->currentCartId();
        if ($cartId !== null) {
            $this->carts->removeItem($cartId, $itemId);
        }
    }

    /** Limite da variante: disponível em estoque ou o máximo por linha (sob encomenda). */
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

    /**
     * @param array<string, mixed> $variant
     * @param int $variantTotal unidades da variante no carrinho após a mudança (todas as linhas)
     * @param int $lineTotal    unidades da linha após a mudança
     */
    private function assertStock(array $variant, int $variantTotal, int $lineTotal): void
    {
        $max = (int) $this->config->get('cart.max_quantity', 99);
        if ($variant['stock_mode'] !== 'stock') {
            if ($lineTotal > $max) {
                throw new BusinessRuleException("A quantidade máxima por item é {$max}.");
            }

            return;
        }

        $limit = $this->limitFor($variant);
        if ($limit === 0) {
            throw new BusinessRuleException('Este produto está esgotado no momento.');
        }
        if ($variantTotal > $limit) {
            throw new BusinessRuleException("Temos apenas {$limit} unidade(s) disponível(is) deste produto.");
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
