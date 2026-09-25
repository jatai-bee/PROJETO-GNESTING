<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\OrderRepository;

final class AccountController extends Controller
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    public function index(Request $request): Response
    {
        $customer = $request->attribute('customer');

        return $this->render('store/account/index', [
            'title' => 'Minha conta | G-Nesting',
            'customer' => $customer,
            'orders' => $this->orders->forCustomer((int) $customer['customer_id'], 5),
        ]);
    }

    public function orders(Request $request): Response
    {
        $customer = $request->attribute('customer');

        return $this->render('store/account/orders', [
            'title' => 'Meus pedidos | G-Nesting',
            'orders' => $this->orders->forCustomer((int) $customer['customer_id']),
        ]);
    }
}
