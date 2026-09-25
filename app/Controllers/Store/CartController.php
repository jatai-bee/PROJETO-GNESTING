<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Auth;
use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\CartService;

/**
 * Carrinho. Todas as alterações são POST com CSRF e terminam em redirect (PRG).
 * Quantidades chegam como texto e são validadas no CartService.
 */
final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly Auth $auth,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->render('store/cart/show', [
            'title' => 'Carrinho | G-Nesting',
            'cart' => $this->cart->summary(),
            'noindex' => true,
        ]);
    }

    public function add(Request $request): Response
    {
        $variantId = $this->intInput($request, 'variant_id');
        $quantity = $this->intInput($request, 'quantity', 1);
        $back = $this->safeRedirectPath($request->string('back'), '/carrinho');

        try {
            $this->cart->add($variantId, $quantity, $this->auth->customer()['customer_id'] ?? null);
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());

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

    public function remove(Request $request): Response
    {
        $this->cart->remove((int) $request->param('id'));
        $this->flash('success', 'Item removido do carrinho.');

        return $this->redirect('/carrinho');
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
