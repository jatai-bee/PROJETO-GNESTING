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
use GNesting\Enums\OrderStatus;
use GNesting\Enums\PaymentStatus;
use GNesting\Repositories\OrderRepository;
use GNesting\Repositories\ProductionJobRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\OrderNotifier;
use GNesting\Services\OrderStatusService;

/**
 * Pedidos no painel. Acesso: gestor, produção e atendimento (docs/03 §5):
 * - gestor: qualquer transição permitida, cancelamento com estorno, mensagens;
 * - produção: avança as etapas de produção (inclui retrabalho);
 * - atendimento: consulta, notas internas, mensagens ao cliente, reenvio do link.
 */
final class OrderController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderStatusService $status,
        private readonly OrderNotifier $notifier,
        private readonly ProductionJobRepository $jobs,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request): Response
    {
        $status = $request->queryString('status', 30);
        $filters = [
            'status' => $status === 'open' || OrderStatus::tryFrom($status) !== null ? $status : '',
            'payment' => PaymentStatus::tryFrom($request->queryString('pagamento', 30)) !== null ? $request->queryString('pagamento', 30) : '',
            'q' => $request->queryString('q', 80),
            'from' => $this->dateBoundary($request->queryString('de', 10), false),
            'to' => $this->dateBoundary($request->queryString('ate', 10), true),
        ];
        $paginator = new Paginator($this->orders->adminCount($filters), $request->queryInt('pagina', 1), self::PER_PAGE);

        return $this->render('admin/orders/index', [
            'title' => 'Pedidos | Painel',
            'orders' => $this->orders->adminList($filters, $paginator->perPage, $paginator->offset()),
            'paginator' => $paginator,
            'filters' => $filters + ['de' => $request->queryString('de', 10), 'ate' => $request->queryString('ate', 10)],
            'cards' => $request->queryString('visao', 10) === 'cartoes',
            'counts' => $this->orders->statusCounts(),
        ], 'admin');
    }

    public function show(Request $request): Response
    {
        $order = $this->order($request);
        $role = $this->role();
        $current = OrderStatus::from((string) $order['status']);

        return $this->render('admin/orders/show', [
            'title' => 'Pedido ' . $order['number'] . ' | Painel',
            'order' => $order,
            'items' => $this->orders->items((int) $order['id']),
            'payments' => $this->orders->payments((int) $order['id']),
            'shipment' => $this->orders->latestShipment((int) $order['id']),
            'history' => $this->orders->history((int) $order['id']),
            'notes' => $this->orders->notes((int) $order['id']),
            'targets' => array_values(array_filter(
                OrderStatusService::targetsFor($role, $current),
                static fn (OrderStatus $s): bool => $s !== OrderStatus::Cancelled
            )),
            'canCancel' => in_array(OrderStatus::Cancelled, OrderStatusService::targetsFor($role, $current), true),
            'canMessage' => $role->isAllowed(['manager', 'support']),
            'fullCpf' => $role->isAllowed(['manager']),
            'isPaid' => $this->orders->paidPayment((int) $order['id']) !== null && $current !== OrderStatus::AwaitingPayment,
            'jobs' => $this->jobs->forOrder((int) $order['id']),
            'canSeeProduction' => $role->isAllowed(['manager', 'production']),
        ], 'admin');
    }

    public function status(Request $request): Response
    {
        $order = $this->order($request);
        $target = OrderStatus::tryFrom($request->string('target'));
        $current = OrderStatus::from((string) $order['status']);

        if ($target === null || $target === OrderStatus::Cancelled || !in_array($target, OrderStatusService::targetsFor($this->role(), $current), true)) {
            throw HttpException::forbidden();
        }

        $trackingUrl = $request->string('tracking_url');

        try {
            $this->status->transition((int) $order['id'], $target, 'admin', $this->userId(), mb_substr($request->string('note'), 0, 500), [
                'carrier' => mb_substr($request->string('carrier'), 0, 60),
                'service' => mb_substr($request->string('service'), 0, 60),
                'tracking_code' => mb_substr(strtoupper($request->string('tracking_code')), 0, 60),
                'tracking_url' => $trackingUrl,
            ]);
            $this->flash('success', "Pedido movido para \"{$target->label()}\".");
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->back($order);
    }

    public function cancel(Request $request): Response
    {
        $order = $this->order($request);
        $current = OrderStatus::from((string) $order['status']);
        if (!in_array(OrderStatus::Cancelled, OrderStatusService::targetsFor($this->role(), $current), true)) {
            throw HttpException::forbidden();
        }

        try {
            $refund = in_array($request->string('refund'), ['gateway', 'manual'], true) ? $request->string('refund') : 'none';
            $this->status->cancel((int) $order['id'], $request->string('reason'), 'admin', $this->userId(), $refund);
            $this->flash('success', 'Pedido cancelado. O cliente foi avisado por e-mail.');
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->back($order);
    }

    public function note(Request $request): Response
    {
        $order = $this->order($request);
        $body = trim($request->string('body'));
        if ($body === '' || mb_strlen($body) > 2000) {
            $this->flash('error', 'Escreva a nota (até 2000 caracteres).');
        } else {
            $this->orders->addNote((int) $order['id'], $this->userId(), 'internal', $body);
            $this->flash('success', 'Nota interna registrada.');
        }

        return $this->back($order);
    }

    public function message(Request $request): Response
    {
        $order = $this->order($request);
        if (!$this->role()->isAllowed(['manager', 'support'])) {
            throw HttpException::forbidden();
        }
        $body = trim($request->string('body'));
        if ($body === '' || mb_strlen($body) > 2000) {
            $this->flash('error', 'Escreva a mensagem (até 2000 caracteres).');

            return $this->back($order);
        }

        $noteId = $this->orders->addNote((int) $order['id'], $this->userId(), 'customer', $body);
        if ($this->notifier->message((int) $order['id'], $body)) {
            $this->orders->markNoteEmailed($noteId);
            $this->flash('success', 'Mensagem enviada ao cliente por e-mail e publicada na página do pedido.');
        } else {
            $this->flash('error', 'A mensagem aparece na página do pedido, mas o e-mail não foi enviado. Verifique a configuração de e-mail.');
        }

        return $this->back($order);
    }

    public function resendLink(Request $request): Response
    {
        $order = $this->order($request);
        if (!$this->role()->isAllowed(['manager', 'support'])) {
            throw HttpException::forbidden();
        }
        if ($this->notifier->link((int) $order['id'])) {
            $this->flash('success', 'Link do pedido reenviado para ' . $order['customer_email'] . '.');
        } else {
            $this->flash('error', 'Não foi possível enviar o e-mail. Verifique a configuração de e-mail.');
        }

        return $this->back($order);
    }

    /** @return array<string, mixed> */
    private function order(Request $request): array
    {
        return $this->orders->find((int) $request->param('id')) ?? throw HttpException::notFound();
    }

    /** @param array<string, mixed> $order */
    private function back(array $order): Response
    {
        return $this->redirect('/admin/pedidos/' . $order['id']);
    }

    private function role(): AdminRole
    {
        return AdminRole::from((string) ($this->auth->admin()['role'] ?? 'support'));
    }

    private function userId(): ?int
    {
        return isset($this->auth->admin()['user_id']) ? (int) $this->auth->admin()['user_id'] : null;
    }

    /** "2026-09-25" (horário da loja) → limite em UTC; vazio/inválido = sem filtro. */
    private function dateBoundary(string $date, bool $endOfDay): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone((string) config('app.timezone', 'America/Sao_Paulo')));
        if ($parsed === false) {
            return '';
        }

        return $parsed->modify($endOfDay ? '+1 day' : '+0 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
