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
use GNesting\Services\BusinessRuleException;
use GNesting\Services\CustomerPrivacyService;

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
        private readonly CustomerPrivacyService $privacy,
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
            'isOwner' => ($this->auth->admin()['role'] ?? '') === AdminRole::Owner->value,
        ], 'admin');
    }

    /** LGPD: todos os dados do cliente em JSON (somente proprietário; fica na auditoria). */
    public function export(Request $request): Response
    {
        $id = (int) $request->param('id');
        try {
            $data = $this->privacy->export($id);
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/admin/clientes/' . $id);
        }

        return Response::json($data)
            ->withHeader('Content-Disposition', 'attachment; filename="dados-cliente-' . $id . '.json"');
    }

    /** LGPD: anonimiza o cadastro a pedido do titular. Exige digitar ANONIMIZAR. */
    public function anonymize(Request $request): Response
    {
        $id = (int) $request->param('id');
        if ($request->string('confirm') !== 'ANONIMIZAR') {
            $this->flash('error', 'Para confirmar, digite ANONIMIZAR no campo.');

            return $this->redirect('/admin/clientes/' . $id);
        }
        try {
            $result = $this->privacy->anonymize($id);
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/admin/clientes/' . $id);
        }
        $this->flash('success', sprintf(
            'Cadastro anonimizado. %d pedido(s) mantido(s) pela obrigação fiscal; %d pedido(s) com mais de %d anos também anonimizado(s).',
            $result['orders_kept'], $result['orders_anonymized'], $result['retention_years'],
        ));

        return $this->redirect('/admin/clientes/' . $id);
    }

    private function canSeeFullCpf(): bool
    {
        return AdminRole::tryFrom((string) ($this->auth->admin()['role'] ?? ''))?->isAllowed(['manager']) ?? false;
    }
}
