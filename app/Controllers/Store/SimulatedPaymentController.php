<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Enums\PaymentStatus;
use GNesting\Repositories\OrderRepository;
use GNesting\Services\Payment\GatewayPayment;
use GNesting\Services\PaymentService;

/**
 * Página de pagamento SIMULADA (desenvolvimento): substitui o Mercado Pago quando
 * PAYMENT_PROVIDER=simulado. Inexistente (404) em produção ou com outro provedor.
 */
final class SimulatedPaymentController extends Controller
{
    private const OUTCOMES = [
        'aprovar' => [PaymentStatus::Paid, 'aprovado'],
        'pendente' => [PaymentStatus::Pending, 'pendente'],
        'recusar' => [PaymentStatus::Failed, 'recusado'],
    ];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly OrderRepository $orders,
    ) {
    }

    public function show(Request $request): Response
    {
        $payment = $this->payment($request);

        return $this->render('store/checkout/simulated', [
            'title' => 'Pagamento simulado | G-Nesting',
            'noindex' => true,
            'payment' => $payment,
            'order' => $this->orders->find((int) $payment['order_id']),
        ]);
    }

    public function complete(Request $request): Response
    {
        $payment = $this->payment($request);
        [$status, $label] = self::OUTCOMES[$request->string('resultado')] ?? throw HttpException::notFound();

        $this->payments->apply(new GatewayPayment(
            id: 'SIMPAY-' . bin2hex(random_bytes(6)),
            orderNumber: (string) $payment['order_number'],
            status: $status,
            amountCents: (int) $payment['amount_cents'],
            method: 'pix',
            failureReason: $status === PaymentStatus::Failed ? 'recusado (simulação)' : null,
        ), 'webhook');

        return $this->redirect('/pedido/' . $payment['order_number'] . '/confirmacao?status=' . $label);
    }

    /** @return array<string, mixed> */
    private function payment(Request $request): array
    {
        if (config('app.env') === 'production' || $this->payments->providerName() !== 'simulado') {
            throw HttpException::notFound();
        }

        return $this->orders->findPaymentByCheckout('simulado', (string) $request->param('referencia')) ?? throw HttpException::notFound();
    }
}
