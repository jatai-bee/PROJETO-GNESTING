<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Auth;
use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Paginator;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Enums\AdminRole;
use GNesting\Repositories\AddressRepository;
use GNesting\Repositories\CustomerRepository;
use GNesting\Repositories\OrderRepository;

/**
 * Clientes (consulta: gestor e atendimento). O atendimento vê o CPF mascarado
 * (minimização de dados — LGPD); o gestor vê completo para nota fiscal e transporte.
 */
final class CustomerController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly OrderRepository $orders,
        private readonly AddressRepository $addresses,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request): Response
    {
        $q = $request->queryString('q', 80);
        $paginator = new Paginator($this->customers->adminCount($q), $request->queryInt('pagina', 1), self::PER_PAGE);

        return $this->render('admin/customers/index', [
            'title' => 'Clientes | Painel',
            'customers' => $this->customers->adminList($q, $paginator->perPage, $paginator->offset()),
            'paginator' => $paginator,
            'q' => $q,
            'fullCpf' => $this->canSeeFullCpf(),
        ], 'admin');
    }

    public function show(Request $request): Response
    {
        $customer = $this->customers->adminFind((int) $request->param('id')) ?? throw HttpException::notFound();

        return $this->render('admin/customers/show', [
            'title' => $customer['name'] . ' | Clientes | Painel',
            'customer' => $customer,
            'orders' => $this->orders->adminList(['customer_id' => (int) $customer['id']], 100, 0),
            'addresses' => $this->addresses->forCustomer((int) $customer['id']),
            'fullCpf' => $this->canSeeFullCpf(),
        ], 'admin');
    }

    private function canSeeFullCpf(): bool
    {
        return AdminRole::tryFrom((string) ($this->auth->admin()['role'] ?? ''))?->isAllowed(['manager']) ?? false;
    }
}
