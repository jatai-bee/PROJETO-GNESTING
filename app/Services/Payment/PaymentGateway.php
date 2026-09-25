<?php

declare(strict_types=1);

namespace GNesting\Services\Payment;

use GNesting\Core\Request;

/**
 * Provedor de pagamento com checkout HOSPEDADO pelo próprio provedor
 * (dados de cartão nunca passam pelo servidor — PCI-DSS SAQ-A).
 */
interface PaymentGateway
{
    /** Identificador gravado em payments.provider (ex.: mercadopago). */
    public function name(): string;

    /**
     * Cria a sessão de checkout.
     *
     * @param array<string, mixed>       $order  pedido (number, total_cents, customer_name, customer_email, shipping_cents...)
     * @param list<array<string, mixed>> $items  itens do pedido (snapshot)
     * @param array{success: string, pending: string, failure: string, notification: string} $urls
     * @return array{reference: string, url: string}
     * @throws \RuntimeException provedor indisponível ou recusou a criação
     */
    public function createCheckout(array $order, array $items, array $urls): array;

    /**
     * Estorno TOTAL de um pagamento aprovado (cancelamento de pedido pago).
     *
     * @throws \RuntimeException provedor recusou ou está indisponível
     */
    public function refund(string $paymentId): void;

    /** Consulta o pagamento no provedor (fonte da verdade). Null = não encontrado. */
    public function fetchPayment(string $paymentId): ?GatewayPayment;

    /**
     * Lê um aviso (webhook). Não confia no conteúdo: devolve o id do pagamento a ser reconsultado.
     *
     * @return array{event_id: string, type: string, payment_id: ?string, signature_valid: bool, payload: string}
     */
    public function parseWebhook(Request $request): array;
}
