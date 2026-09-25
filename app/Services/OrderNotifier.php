<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Logger;
use GNesting\Core\View;
use GNesting\Enums\OrderStatus;
use GNesting\Repositories\OrderRepository;
use GNesting\Services\Mail\Mailer;

/**
 * E-mails ao cliente sobre o pedido. Sempre depois da transação e sem derrubar a operação:
 * uma falha de envio fica no log e o fluxo continua.
 */
final class OrderNotifier
{
    /** Status que geram e-mail: assunto e texto principal. */
    private const STATUS_MESSAGES = [
        'production_pending' => ['Pagamento aprovado — pedido {n}',
            'O pagamento foi confirmado e o seu pedido entrou na fila de produção. Ele fica pronto em até {d} dias úteis e depois segue para entrega.'],
        'in_production' => ['Seu pedido {n} está em produção',
            'Começamos a produzir o seu pedido: recorte, acabamento e conferência. Avisamos quando ele for enviado.'],
        'shipped' => ['Pedido {n} enviado',
            'Seu pedido saiu para entrega.'],
        'delivered' => ['Pedido {n} entregue',
            'Seu pedido foi entregue. Esperamos que você goste! Se algo não estiver perfeito, responda este e-mail.'],
        'cancelled' => ['Pedido {n} cancelado',
            'Seu pedido foi cancelado.'],
    ];

    public function __construct(
        private readonly Mailer $mailer,
        private readonly View $view,
        private readonly OrderRepository $orders,
        private readonly OrderLink $links,
        private readonly Logger $logger,
    ) {
    }

    public function placed(int $orderId): bool
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return false;
        }

        return $this->send($order, "Pedido {$order['number']} recebido",
            'Recebemos o seu pedido. Assim que o pagamento for confirmado, ele entra na fila de produção.',
            items: $this->orders->items($orderId),
            details: 'Se ainda não concluiu o pagamento, use o link abaixo ("Pagar agora").');
    }

    public function statusChanged(int $orderId, OrderStatus $status): bool
    {
        $message = self::STATUS_MESSAGES[$status->value] ?? null;
        $order = $this->orders->find($orderId);
        if ($message === null || $order === null) {
            return false;
        }
        [$subject, $intro] = str_replace(['{n}', '{d}'], [(string) $order['number'], (string) $order['production_days']], $message);

        $details = null;
        if ($status === OrderStatus::Shipped) {
            $shipment = $this->orders->latestShipment($orderId);
            if ($shipment !== null) {
                $details = trim(implode("\n", array_filter([
                    'Transportadora: ' . $shipment['carrier'] . ($shipment['service'] ? ' — ' . $shipment['service'] : ''),
                    $shipment['tracking_code'] ? 'Código de rastreio: ' . $shipment['tracking_code'] : null,
                    $shipment['tracking_url'] ? 'Rastrear: ' . $shipment['tracking_url'] : null,
                    'Prazo estimado: até ' . $order['shipping_days'] . ' dias úteis.',
                ])));
            }
        } elseif ($status === OrderStatus::Cancelled && $order['cancel_reason']) {
            $details = 'Motivo: ' . $order['cancel_reason']
                . ($order['payment_status'] === 'refunded' ? "\nO valor pago foi estornado pelo mesmo meio de pagamento." : '');
        }

        return $this->send($order, $subject, $intro, details: $details);
    }

    public function message(int $orderId, string $body): bool
    {
        $order = $this->orders->find($orderId);

        return $order !== null && $this->send($order, "Mensagem sobre o pedido {$order['number']}",
            'Temos uma mensagem sobre o seu pedido:', details: $body);
    }

    public function link(int $orderId): bool
    {
        $order = $this->orders->find($orderId);

        return $order !== null && $this->send($order, "Link do pedido {$order['number']}",
            'Aqui está o link para acompanhar o seu pedido.');
    }

    /**
     * @param array<string, mixed>            $order
     * @param list<array<string, mixed>>|null $items
     */
    private function send(array $order, string $subject, string $intro, ?array $items = null, ?string $details = null): bool
    {
        $body = trim((string) preg_replace("/\n{3,}/", "\n\n", $this->view->render('emails/order', [
            'order' => $order, 'intro' => $intro, 'items' => $items, 'details' => $details,
            'link' => $this->links->url((string) $order['number']),
        ])));

        $sent = $this->mailer->send((string) $order['customer_email'], $subject, $body);
        if (!$sent) {
            $this->logger->warning('E-mail ao cliente não enviado', ['order' => $order['number'], 'subject' => $subject]);
        }

        return $sent;
    }
}
