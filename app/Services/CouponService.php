<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Database;
use GNesting\Core\ValidationException;
use GNesting\Repositories\CouponRepository;

/**
 * Cupons de desconto.
 *
 * Tipos: percent (value em pontos-base: 1000 = 10%), fixed (centavos) e free_shipping.
 * Regras: ativo, dentro do período, subtotal mínimo, desconto máximo, limite total e
 * por cliente. O desconto é sempre calculado no servidor sobre o subtotal atual.
 * - frete grátis zera a opção MAIS BARATA (escolher uma mais cara paga a diferença);
 * - o uso é registrado ao fazer o pedido (com trava da linha do cupom) e devolvido se o pedido for cancelado.
 */
final class CouponService
{
    public const TYPES = ['percent' => 'Percentual', 'fixed' => 'Valor fixo', 'free_shipping' => 'Frete grátis'];

    public function __construct(
        private readonly Database $db,
        private readonly CouponRepository $coupons,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<string, mixed> cupom válido para o subtotal @throws BusinessRuleException */
    public function findUsable(string $code, int $subtotalCents, ?int $customerId = null): array
    {
        $code = strtoupper(trim($code));
        $coupon = $code === '' ? null : $this->coupons->findByCode($code);
        if ($coupon === null) {
            throw new BusinessRuleException('Cupom não encontrado. Confira o código.');
        }
        $this->assertUsable($coupon, $subtotalCents, $customerId);

        return $coupon;
    }

    /** @param array<string, mixed> $coupon @throws BusinessRuleException */
    public function assertUsable(array $coupon, int $subtotalCents, ?int $customerId = null): void
    {
        $now = now_utc();
        if (!(bool) $coupon['is_active']
            || ($coupon['starts_at'] !== null && $coupon['starts_at'] > $now)
            || ($coupon['ends_at'] !== null && $coupon['ends_at'] <= $now)) {
            throw new BusinessRuleException("O cupom {$coupon['code']} não está válido no momento.");
        }
        if ($coupon['usage_limit'] !== null && (int) $coupon['times_used'] >= (int) $coupon['usage_limit']) {
            throw new BusinessRuleException("O cupom {$coupon['code']} já foi totalmente utilizado.");
        }
        if ($coupon['min_subtotal_cents'] !== null && $subtotalCents < (int) $coupon['min_subtotal_cents']) {
            throw new BusinessRuleException("O cupom {$coupon['code']} vale para compras a partir de " . money((int) $coupon['min_subtotal_cents']) . '.');
        }
        if ($customerId !== null && $coupon['usage_limit_per_customer'] !== null
            && $this->coupons->redemptionsByCustomer((int) $coupon['id'], $customerId) >= (int) $coupon['usage_limit_per_customer']) {
            throw new BusinessRuleException("Você já usou o cupom {$coupon['code']} o máximo de vezes permitido.");
        }
    }

    /**
     * Desconto em centavos: sobre os itens e, no frete grátis, sobre o frete.
     *
     * @param array<string, mixed> $coupon
     * @return array{items: int, shipping: int}
     */
    public function discount(array $coupon, int $subtotalCents, int $chosenShippingCents = 0, int $cheapestShippingCents = 0): array
    {
        $items = match ($coupon['type']) {
            'percent' => intdiv($subtotalCents * (int) $coupon['value'], 10000),
            'fixed' => min((int) $coupon['value'], $subtotalCents),
            default => 0,
        };
        if ($coupon['max_discount_cents'] !== null) {
            $items = min($items, (int) $coupon['max_discount_cents']);
        }
        $shipping = $coupon['type'] === 'free_shipping' ? min($chosenShippingCents, $cheapestShippingCents) : 0;

        return ['items' => $items, 'shipping' => $shipping];
    }

    /** Texto curto do benefício: "10% de desconto", "R$ 20,00 de desconto", "Frete grátis". */
    public static function describe(array $coupon): string
    {
        return match ($coupon['type']) {
            'percent' => format_decimal(sprintf('%d.%02d', intdiv((int) $coupon['value'], 100), (int) $coupon['value'] % 100)) . '% de desconto'
                . ($coupon['max_discount_cents'] !== null ? ' (até ' . money((int) $coupon['max_discount_cents']) . ')' : ''),
            'fixed' => money((int) $coupon['value']) . ' de desconto',
            default => 'Frete grátis (opção mais econômica)',
        };
    }

    // ---- Painel ------------------------------------------------------------------

    /** @param array<string, mixed> $input @throws ValidationException */
    public function create(array $input): int
    {
        return $this->db->transaction(function () use ($input): int {
            $data = $this->prepare($input, null);
            $id = $this->coupons->create($data);
            $this->audit->record(AuditService::CREATE, 'coupon', $id, null, $data);

            return $id;
        });
    }

    /** @param array<string, mixed> $input @throws ValidationException|BusinessRuleException */
    public function update(int $id, array $input): void
    {
        $this->db->transaction(function () use ($id, $input): void {
            $current = $this->coupons->find($id) ?? throw new BusinessRuleException('Cupom não encontrado.');
            $data = $this->prepare($input, $id);
            $this->coupons->update($id, $data);
            $this->audit->recordChanges(AuditService::UPDATE, 'coupon', $id, $current, $data);
        });
    }

    /** Usado ao menos uma vez: não exclui (histórico dos pedidos) — desative. @throws BusinessRuleException */
    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $coupon = $this->coupons->find($id) ?? throw new BusinessRuleException('Cupom não encontrado.');
            if ((int) $coupon['times_used'] > 0) {
                throw new BusinessRuleException("O cupom {$coupon['code']} já foi usado em pedidos. Desative-o em vez de excluir.");
            }
            $this->coupons->delete($id);
            $this->audit->record(AuditService::DELETE, 'coupon', $id, ['code' => $coupon['code']]);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function prepare(array $input, ?int $id): array
    {
        $errors = [];
        $code = strtoupper((string) $input['code']);
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/', $code)) {
            $errors['code'] = 'Use de 3 a 40 letras, números, "-" ou "_", sem espaços.';
        } elseif ($this->coupons->codeExists($code, $id)) {
            $errors['code'] = 'Já existe um cupom com este código.';
        }
        $type = (string) $input['type'];
        if (!array_key_exists($type, self::TYPES)) {
            $errors['type'] = 'Escolha o tipo.';
        }
        $value = match ($type) {
            'percent' => $input['percent_basis_points'],
            'fixed' => $input['fixed_cents'],
            default => 0,
        };
        if ($type === 'percent' && ($value === null || $value < 1 || $value > 10000)) {
            $errors['percent'] = 'Informe um percentual entre 0,01 e 100.';
        }
        if ($type === 'fixed' && ($value === null || $value < 1)) {
            $errors['fixed'] = 'Informe o valor do desconto.';
        }
        if ($input['starts_at'] !== null && $input['ends_at'] !== null && $input['ends_at'] <= $input['starts_at']) {
            $errors['ends_at'] = 'O fim precisa ser depois do início.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'code' => $code,
            'description' => ((string) $input['description']) === '' ? null : (string) $input['description'],
            'type' => $type,
            'value' => (int) $value,
            'min_subtotal_cents' => $input['min_subtotal_cents'],
            'max_discount_cents' => $type === 'percent' ? $input['max_discount_cents'] : null,
            'starts_at' => $input['starts_at'],
            'ends_at' => $input['ends_at'],
            'usage_limit' => $input['usage_limit'],
            'usage_limit_per_customer' => $input['usage_limit_per_customer'],
            'is_active' => (int) (bool) $input['is_active'],
        ];
    }
}
