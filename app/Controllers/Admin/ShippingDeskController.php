<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Auth;
use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Enums\AdminRole;
use GNesting\Enums\OrderStatus;
use GNesting\Repositories\OrderRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\OrderStatusService;

/**
 * Expedição (gestor e produção): pedidos prontos para envio, romaneio para
 * conferência/impressão, despacho com rastreio e confirmação de entrega.
 */
final class ShippingDeskController extends Controller
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderStatusService $status,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request): Response
    {
        $ready = $this->orders->adminList(['status' => OrderStatus::ReadyToShip->value], 200, 0);
        foreach ($ready as &$order) {
            $order['items'] = $this->orders->items((int) $order['id']);
            $order['weight_g'] = $this->orders->packageWeight((int) $order['id']);
            $order['full'] = $this->orders->find((int) $order['id']);
        }
        unset($order);

        return $this->render('admin/shipping/index', [
            'title' => 'Expedição | Painel',
            'ready' => $ready,
            'inTransit' => $this->orders->adminList(['status' => OrderStatus::Shipped->value], 200, 0),
        ], 'admin');
    }

    public function slip(Request $request): Response
    {
        $order = $this->order($request);

        return $this->render('admin/shipping/slip', [
            'title' => 'Romaneio ' . $order['number'],
            'order' => $order,
            'items' => $this->orders->items((int) $order['id']),
            'weight' => $this->orders->packageWeight((int) $order['id']),
        ], 'print');
    }

    public function ship(Request $request): Response
    {
        $order = $this->order($request);
        $this->authorize($order, OrderStatus::Shipped);
        try {
            $this->status->transition((int) $order['id'], OrderStatus::Shipped, 'admin', $this->userId(), null, [
                'carrier' => mb_substr($request->string('carrier'), 0, 60),
                'service' => mb_substr($request->string('service'), 0, 60),
                'tracking_code' => mb_substr(strtoupper($request->string('tracking_code')), 0, 60),
                'tracking_url' => $request->string('tracking_url'),
            ]);
            $this->flash('success', "{$order['number']} enviado. O cliente recebeu o rastreio por e-mail.");
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/expedicao');
    }

    public function delivered(Request $request): Response
    {
        $order = $this->order($request);
        $this->authorize($order, OrderStatus::Delivered);
        try {
            $this->status->transition((int) $order['id'], OrderStatus::Delivered, 'admin', $this->userId());
            $this->flash('success', "{$order['number']} marcado como entregue.");
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/expedicao');
    }

    /** @param array<string, mixed> $order */
    private function authorize(array $order, OrderStatus $target): void
    {
        $role = AdminRole::from((string) ($this->auth->admin()['role'] ?? 'support'));
        if (!in_array($target, OrderStatusService::targetsFor($role, OrderStatus::from((string) $order['status'])), true)) {
            throw HttpException::forbidden();
        }
    }

    /** @return array<string, mixed> */
    private function order(Request $request): array
    {
        return $this->orders->find((int) $request->param('id')) ?? throw HttpException::notFound();
    }

    private function userId(): ?int
    {
        return isset($this->auth->admin()['user_id']) ? (int) $this->auth->admin()['user_id'] : null;
    }
}
