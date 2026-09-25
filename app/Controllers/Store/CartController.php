<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Auth;
use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\ValidationException;
use GNesting\Services\Auth\TooManyAttemptsException;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\CartService;
use GNesting\Services\PersonalizationService;
use GNesting\Services\RateLimiter;
use GNesting\Services\RelatedProducts;

/**
 * Carrinho. Todas as alterações são POST com CSRF e terminam em redirect (PRG).
 * Quantidades chegam como texto e são validadas no CartService.
 */
final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly Auth $auth,
        private readonly RateLimiter $rateLimiter,
        private readonly RelatedProducts $related,
    ) {
    }

    public function show(Request $request): Response
    {
        $summary = $this->cart->summary();

        return $this->render('store/cart/show', [
            'title' => 'Carrinho | G-Nesting',
            'cart' => $summary,
            'suggestions' => $this->related->forCart(array_values(array_unique(array_filter(array_map(
                static fn (array $item): int => (int) ($item['product_id'] ?? 0),
                $summary['items']
            ))))),
            'noindex' => true,
        ]);
    }

    public function add(Request $request): Response
    {
        $variantId = $this->intInput($request, 'variant_id');
        $quantity = $this->intInput($request, 'quantity', 1);
        $back = $this->safeRedirectPath($request->string('back'), '/carrinho');

        $personalization = $this->personalizationInput($request);

        try {
            $this->cart->add($variantId, $quantity, $personalization, $this->auth->customer()['customer_id'] ?? null);
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
            $this->flash('old', $this->oldInput($request, $personalization));

            return $this->redirect($back);
        } catch (ValidationException $e) {
            // Volta para a página do produto com as mensagens e o que foi digitado
            $this->flash('errors', $e->errors());
            $this->flash('old', $this->oldInput($request, $personalization));
            $this->flash('error', 'Confira a personalização.');

            return $this->redirect($back);
        }

        $this->flash('success', 'Produto adicionado ao carrinho.');

        return $this->redirect('/carrinho');
    }

    public function update(Request $request): Response
    {
        try {
            $this->cart->update((int) $request->param('id'), $this->intInput($request, 'quantity'));
            $this->flash('success', 'Carrinho atualizado.');
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/carrinho');
    }

    public function applyCoupon(Request $request): Response
    {
        // docs/05: tentativas de cupom limitadas (evita adivinhar códigos)
        $key = 'coupon:ip:' . $request->ip();
        [$max, $window] = config('security.rate_limits.coupon', [10, 600]);
        try {
            $this->rateLimiter->ensureNotBlocked($key);
        } catch (TooManyAttemptsException $e) {
            $this->flash('error', 'Muitas tentativas de cupom. Tente de novo em alguns minutos.');

            return $this->redirect('/carrinho');
        }
        $this->rateLimiter->hit($key, (int) $max, (int) $window);

        try {
            $benefit = $this->cart->applyCoupon(mb_substr($request->string('code'), 0, 40));
            $this->flash('success', "Cupom aplicado: {$benefit}.");
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/carrinho');
    }

    public function removeCoupon(Request $request): Response
    {
        $this->cart->removeCoupon();
        $this->flash('success', 'Cupom removido.');

        return $this->redirect('/carrinho');
    }

    public function remove(Request $request): Response
    {
        $this->cart->remove((int) $request->param('id'));
        $this->flash('success', 'Item removido do carrinho.');

        return $this->redirect('/carrinho');
    }

    /**
     * Campos "pers_{rule_id}". Quais regras existem e o que aceitam é decidido pelo serviço;
     * aqui só se coleta texto (arrays e campos desconhecidos são ignorados).
     *
     * @return array<int, string>
     */
    private function personalizationInput(Request $request): array
    {
        $input = [];
        foreach (array_keys($request->all()) as $key) {
            if (is_string($key) && preg_match('/^' . PersonalizationService::FIELD_PREFIX . '(\d{1,18})$/', $key, $m)) {
                $input[(int) $m[1]] = mb_substr($request->string($key), 0, 255);
            }
        }

        return $input;
    }

    /**
     * @param array<int, string> $personalization
     * @return array<string, string>
     */
    private function oldInput(Request $request, array $personalization): array
    {
        $old = ['quantity' => $request->string('quantity'), 'variant_id' => $request->string('variant_id')];
        foreach ($personalization as $ruleId => $value) {
            $old[PersonalizationService::FIELD_PREFIX . $ruleId] = $value;
        }

        return $old;
    }

    /** Inteiro do formulário; texto inválido vira -1 (recusado pela validação do serviço). */
    private function intInput(Request $request, string $key, int $default = -1): int
    {
        $raw = $request->string($key);
        if ($raw === '') {
            return $default;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);

        return $value === false ? -1 : $value;
    }
}
