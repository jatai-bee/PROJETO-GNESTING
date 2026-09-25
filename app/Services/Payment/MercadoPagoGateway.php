<?php

declare(strict_types=1);

namespace GNesting\Services\Payment;

use GNesting\Core\Request;
use GNesting\Enums\PaymentStatus;
use RuntimeException;

/**
 * Mercado Pago — Checkout Pro (página de pagamento do Mercado Pago: Pix, cartão, boleto).
 *
 * - Preferência: POST /checkout/preferences com itens, frete, external_reference = número do pedido.
 * - Webhook: assinatura x-signature (HMAC-SHA256 com a chave secreta do painel do MP sobre
 *   "id:{data.id};request-id:{x-request-id};ts:{ts};"). O conteúdo do aviso não é usado:
 *   o pagamento é sempre reconsultado em GET /v1/payments/{id}.
 * Credenciais: MERCADOPAGO_ACCESS_TOKEN e MERCADOPAGO_WEBHOOK_SECRET (.env).
 */
final class MercadoPagoGateway implements PaymentGateway
{
    private const API = 'https://api.mercadopago.com';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $accessToken,
        private readonly string $webhookSecret,
        private readonly int $maxInstallments = 12,
    ) {
    }

    public function name(): string
    {
        return 'mercadopago';
    }

    public function createCheckout(array $order, array $items, array $urls): array
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = [
                'id' => (string) $item['sku'],
                'title' => mb_substr($item['product_name'] . ($item['variant_name'] ? ' — ' . $item['variant_name'] : ''), 0, 250),
                'quantity' => (int) $item['quantity'],
                'unit_price' => $this->reais((int) $item['unit_price_cents'] + (int) $item['personalization_cents']),
                'currency_id' => 'BRL',
            ];
        }
        if ((int) $order['shipping_cents'] > 0) {
            $lines[] = [
                'id' => 'frete', 'title' => 'Frete — ' . ($order['shipping_service'] ?? 'entrega'),
                'quantity' => 1, 'unit_price' => $this->reais((int) $order['shipping_cents']), 'currency_id' => 'BRL',
            ];
        }

        $payload = [
            'items' => $lines,
            'payer' => ['name' => (string) $order['customer_name'], 'email' => (string) $order['customer_email']],
            'external_reference' => (string) $order['number'],
            'back_urls' => ['success' => $urls['success'], 'pending' => $urls['pending'], 'failure' => $urls['failure']],
            'auto_return' => 'approved',
            'notification_url' => $urls['notification'],
            'statement_descriptor' => 'GNESTING',
            'payment_methods' => ['installments' => $this->maxInstallments],
        ];

        $response = $this->call('POST', '/checkout/preferences', $payload, 'pref-' . $order['number'] . '-' . bin2hex(random_bytes(4)));
        if (!isset($response['id'], $response['init_point'])) {
            throw new RuntimeException('Mercado Pago não retornou o link de pagamento.');
        }

        return ['reference' => (string) $response['id'], 'url' => (string) $response['init_point']];
    }

    public function refund(string $paymentId): void
    {
        if (!preg_match('/^\d{1,20}$/', $paymentId)) {
            throw new RuntimeException('Pagamento inválido para estorno.');
        }
        // Corpo vazio = estorno total. Chave de idempotência: repetir o pedido não estorna duas vezes.
        $this->call('POST', '/v1/payments/' . $paymentId . '/refunds', null, 'refund-' . $paymentId);
    }

    public function fetchPayment(string $paymentId): ?GatewayPayment
    {
        if (!preg_match('/^\d{1,20}$/', $paymentId)) {
            return null;
        }
        try {
            $data = $this->call('GET', '/v1/payments/' . $paymentId);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }

        return new GatewayPayment(
            id: (string) $data['id'],
            orderNumber: (string) ($data['external_reference'] ?? ''),
            status: $this->status((string) ($data['status'] ?? '')),
            amountCents: (int) round(((float) ($data['transaction_amount'] ?? 0)) * 100),
            method: $this->method((string) ($data['payment_type_id'] ?? ''), (string) ($data['payment_method_id'] ?? '')),
            installments: isset($data['installments']) ? (int) $data['installments'] : null,
            cardBrand: in_array($data['payment_type_id'] ?? '', ['credit_card', 'debit_card'], true) ? mb_substr((string) ($data['payment_method_id'] ?? ''), 0, 20) : null,
            cardLast4: isset($data['card']['last_four_digits']) ? (string) $data['card']['last_four_digits'] : null,
            failureReason: ($data['status'] ?? '') === 'rejected' ? mb_substr((string) ($data['status_detail'] ?? 'recusado'), 0, 200) : null,
        );
    }

    public function parseWebhook(Request $request): array
    {
        $body = json_decode($request->rawBody(), true);
        $body = is_array($body) ? $body : [];

        // "data.id" chega na query string (o PHP troca o ponto por "_") e no corpo
        $dataId = (string) ($request->query('data_id') ?? $request->query('data.id') ?? ($body['data']['id'] ?? ''));
        $type = (string) ($request->query('type') ?? ($body['type'] ?? $body['topic'] ?? ''));
        $requestId = (string) $request->header('X-Request-Id');

        return [
            'event_id' => mb_substr((string) ($body['id'] ?? '') ?: ($requestId ?: hash('sha256', $request->rawBody())), 0, 100),
            'type' => mb_substr($type . (isset($body['action']) ? ':' . $body['action'] : ''), 0, 60),
            'payment_id' => $type === 'payment' && preg_match('/^\d{1,20}$/', $dataId) ? $dataId : null,
            'signature_valid' => $this->validSignature((string) $request->header('X-Signature'), $requestId, $dataId),
            'payload' => $request->rawBody() !== '' ? $request->rawBody() : '{}',
        ];
    }

    /** x-signature: "ts=1704908010,v1=<hmac>" */
    private function validSignature(string $header, string $requestId, string $dataId): bool
    {
        if ($this->webhookSecret === '' || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }
        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if ($ts === '' || $v1 === '' || !ctype_digit($ts)) {
            return false;
        }
        // Sem janela de tempo: reenvios do MP podem chegar bem depois, e repetir um aviso
        // é inofensivo (o conteúdo não é usado; o status é reconsultado na API).

        $manifest = 'id:' . (ctype_alnum($dataId) ? strtolower($dataId) : $dataId) . ';'
            . ($requestId !== '' ? 'request-id:' . $requestId . ';' : '')
            . 'ts:' . $ts . ';';

        return hash_equals(hash_hmac('sha256', $manifest, $this->webhookSecret), $v1);
    }

    private function status(string $status): PaymentStatus
    {
        return match ($status) {
            'approved' => PaymentStatus::Paid,
            'authorized' => PaymentStatus::Authorized,
            'rejected' => PaymentStatus::Failed,
            'cancelled' => PaymentStatus::Cancelled,
            'refunded', 'charged_back' => PaymentStatus::Refunded,
            default => PaymentStatus::Pending, // pending, in_process, in_mediation
        };
    }

    private function method(string $type, string $methodId): string
    {
        return match (true) {
            $methodId === 'pix' || $type === 'bank_transfer' => 'pix',
            $type === 'ticket' => 'boleto',
            $type === 'credit_card', $type === 'debit_card' => 'credit_card',
            default => 'other',
        };
    }

    /** Centavos → número em reais com 2 casas, sem erro de ponto flutuante na serialização. */
    private function reais(int $cents): float
    {
        return (float) sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): array
    {
        if ($this->accessToken === '') {
            throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN não configurado.');
        }
        $headers = ['Authorization' => 'Bearer ' . $this->accessToken, 'Accept' => 'application/json'];
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null) {
            $headers['X-Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->http->request(
            $method,
            self::API . $path,
            $headers,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("Mercado Pago respondeu HTTP {$response['status']} em {$path}.");
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('Resposta inválida do Mercado Pago.');
        }

        return $data;
    }
}
