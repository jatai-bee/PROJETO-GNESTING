<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Auth;
use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\CatalogRepository;
use GNesting\Repositories\WishlistRepository;

/**
 * Favoritos: o coração nos cartões e na ficha do produto. Sem conta, leva ao login e volta para a
 * mesma página (a rota fica fora do grupo "auth" justamente para isso: um POST não pode ser refeito
 * depois do login).
 */
final class FavoriteController extends Controller
{
    public function __construct(
        private readonly Auth $auth,
        private readonly WishlistRepository $wishlist,
        private readonly CatalogRepository $catalog,
    ) {
    }

    public function toggle(Request $request): Response
    {
        $productId = (int) $request->param('id');
        $product = $this->catalog->cardsByIds([$productId])[0] ?? throw HttpException::notFound();
        $back = $this->safeRedirectPath($request->string('voltar'), '/produto/' . $product['slug']);

        $customer = $this->auth->customer();
        if ($customer === null) {
            $this->flash('success', 'Entre na sua conta (ou crie uma) para guardar seus favoritos.');

            return $this->redirect('/entrar?voltar=' . rawurlencode($back));
        }

        $customerId = (int) $customer['customer_id'];
        if ($this->wishlist->has($customerId, $productId)) {
            $this->wishlist->remove($customerId, $productId);
            $this->flash('success', "“{$product['name']}” saiu dos seus favoritos.");
        } else {
            $this->wishlist->add($customerId, $productId);
            $this->flash('success', "“{$product['name']}” está nos seus favoritos.");
        }

        return $this->redirect($back);
    }

    public function index(Request $request): Response
    {
        $customer = $request->attribute('customer');

        return $this->render('store/account/favorites', [
            'title' => 'Meus favoritos | G-Nesting',
            'noindex' => true,
            'products' => $this->catalog->cardsByIds($this->wishlist->productIds((int) $customer['customer_id'])),
        ]);
    }
}
