<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Config;
use GNesting\Core\Database;
use GNesting\Core\Logger;
use GNesting\Core\ValidationException;
use GNesting\Helpers\BrazilianDocument;
use GNesting\Helpers\ZipCode;
use GNesting\Repositories\AddressRepository;
use GNesting\Repositories\CustomerRepository;
use GNesting\Repositories\InventoryRepository;
use GNesting\Repositories\OrderRepository;
use GNesting\Repositories\ProductionSpecRepository;
use GNesting\Services\Auth\AuthService;
use GNesting\Services\Shipping\ShippingCalculator;
use GNesting\Services\Shipping\ShippingOption;

/**
 * Checkout: transforma o carrinho em pedido.
 *
 * Tudo é recalculado no servidor no momento da compra: itens, preços, acréscimos,
 * disponibilidade e frete. Numa única transação: cliente (com ou sem conta), pedido com
 * snapshots, reserva de estoque (atômica), histórico e encerramento do carrinho.
 * O e-mail de confirmação sai depois; se falhar, o pedido continua válido.
 */
final class CheckoutService
{
    public function __construct(
        private readonly Database $db,
        private readonly CartService $cart,
        private readonly ShippingCalculator $shipping,
        private readonly OrderRepository $orders,
        private readonly CustomerRepository $customers,
        private readonly AddressRepository $addresses,
        private readonly InventoryRepository $inventory,
        private readonly ProductionSpecRepository $specs,
        private readonly AuditService $audit,
        private readonly OrderNotifier $notifier,
        private readonly OrderLink $links,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Carrinho pronto para fechar.
     *
     * @return array<string, mixed> resumo do CartService
     * @throws BusinessRuleException
     */
    public function cartForCheckout(): array
    {
        $summary = $this->cart->summary();
        if ($summary['items'] === []) {
            throw new BusinessRuleException('Seu carrinho está vazio.');
        }
        if ($summary['has_issues']) {
            throw new BusinessRuleException('Alguns itens do carrinho precisam de ajuste antes de finalizar.');
        }

        return $summary;
    }

    /**
     * Opções de frete para o CEP e o carrinho atual.
     *
     * @param array<string, mixed>|null $summary
     * @return list<ShippingOption>
     */
    public function quote(string $zip, ?array $summary = null): array
    {
        $zip = ZipCode::normalize($zip);
        if ($zip === null) {
            return [];
        }
        $summary ??= $this->cart->summary();

        return $this->shipping->quote($zip, $this->weight($summary), (int) $summary['subtotal_cents']);
    }

    /**
     * Cria o pedido.
     *
     * @param array<string, mixed> $input  ver CheckoutController::input()
     * @return array{order_id: int, number: string, token: string}
     * @throws ValidationException|BusinessRuleException
     */
    public function place(array $input, ?int $customerId): array
    {
        $summary = $this->cartForCheckout();
        [$contact, $address] = $this->validate($input, $customerId);

        // O cliente precisa ter visto o frete deste CEP (trocou o endereço depois de cotar = recotar)
        if (ZipCode::normalize((string) ($input['quoted_zip'] ?? '')) !== $address['zip_code']) {
            throw new ValidationException(['shipping_code' => 'Calcule o frete para o CEP de entrega informado.']);
        }

        $option = null;
        foreach ($this->quote($address['zip_code'], $summary) as $candidate) {
            if ($candidate->code === $input['shipping_code']) {
                $option = $candidate;
            }
        }
        if ($option === null) {
            throw new ValidationException(['shipping_code' => 'Escolha uma opção de entrega para este CEP.']);
        }

        $result = $this->db->transaction(function () use ($summary, $contact, $address, $option, $customerId, $input): array {
            $customerId = $this->resolveCustomer($customerId, $contact);

            $subtotal = (int) $summary['subtotal_cents'];
            $orderId = $this->orders->create([
                'access_token_hash' => null, // chave do link é derivada do número (OrderLink)
                'customer_id' => $customerId,
                'subtotal_cents' => $subtotal,
                'shipping_cents' => $option->priceCents,
                'total_cents' => $subtotal + $option->priceCents,
                'customer_name' => $contact['name'],
                'customer_email' => $contact['email'],
                'customer_phone' => $contact['phone'],
                'customer_cpf' => $contact['cpf'],
                'ship_recipient' => $address['recipient_name'],
                'ship_zip_code' => $address['zip_code'],
                'ship_street' => $address['street'],
                'ship_number' => $address['number'],
                'ship_complement' => $address['complement'],
                'ship_district' => $address['district'],
                'ship_city' => $address['city'],
                'ship_state' => $address['state'],
                'shipping_carrier' => $option->carrier,
                'shipping_service' => $option->service,
                'shipping_days' => $option->days,
                'production_days' => (int) $summary['lead_days'],
            ]);

            $perVariant = [];
            foreach ($summary['items'] as $item) {
                $minutes = $this->specs->minutes((int) $item['variant_id'])['total'];
                $itemId = $this->orders->addItem($orderId, [
                    'product_id' => (int) $item['product_id'],
                    'variant_id' => (int) $item['variant_id'],
                    'sku' => (string) $item['sku'],
                    'product_name' => (string) $item['name'],
                    'variant_name' => $item['variant_name'],
                    'unit_price_cents' => (int) $item['base_price_cents'],
                    'personalization_cents' => (int) $item['personalization_cents'],
                    'quantity' => (int) $item['quantity'],
                    'line_total_cents' => (int) $item['line_total_cents'],
                    'production_minutes_estimate' => $minutes > 0 ? $minutes : null,
                ]);
                foreach ($item['personalization'] as $choice) {
                    $this->orders->addItemPersonalization($itemId, [
                        'rule_id' => $choice['rule_id'],
                        'field_key' => $choice['field_key'],
                        'label' => $choice['label'],
                        'type' => $choice['type'],
                        'value_text' => (string) ($choice['code'] ?? $choice['value_text']),
                        'value_label' => $choice['type'] === 'select' ? $choice['display'] : null,
                        'price_delta_cents' => (int) $choice['price_delta_cents'],
                    ]);
                }
                $perVariant[(int) $item['variant_id']] = ($perVariant[(int) $item['variant_id']] ?? 0) + (int) $item['quantity'];
            }

            // Reserva atômica: se outro pedido levou a última unidade, nada é gravado
            foreach ($perVariant as $variantId => $quantity) {
                if ($this->inventory->isStockControlled($variantId) && !$this->inventory->reserve($variantId, $quantity, $orderId)) {
                    throw new BusinessRuleException('Um dos produtos acabou de esgotar. Revise o carrinho e tente de novo.');
                }
            }

            $this->orders->addHistory($orderId, null, 'awaiting_payment', 'customer');
            if ($customerId !== null && ($input['save_address'] ?? false) && $input['logged_in']
                && !$this->addresses->exists($customerId, $address['zip_code'], $address['number'], $address['complement'])) {
                $this->addresses->create($customerId, $address);
            }
            $this->cart->convert();

            $order = $this->orders->find($orderId);
            $this->audit->record(AuditService::CREATE, 'order', $orderId, null, [
                'number' => $order['number'], 'total_cents' => $order['total_cents'], 'items' => count($summary['items']),
            ]);

            return ['order_id' => $orderId, 'number' => (string) $order['number'], 'token' => $this->links->token((string) $order['number'])];
        });

        try {
            $this->notifier->placed($result['order_id']);
        } catch (\Throwable $e) {
            $this->logger->error('Falha no e-mail de confirmação do pedido', ['order' => $result['number'], 'error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array{name: string, email: string, cpf: string, phone: string},
     *               1: array{recipient_name: string, zip_code: string, street: string, number: string, complement: ?string, district: string, city: string, state: string}}
     * @throws ValidationException
     */
    private function validate(array $input, ?int $customerId): array
    {
        $errors = [];

        $cpf = BrazilianDocument::cpf((string) $input['cpf']);
        if ($cpf === null) {
            $errors['cpf'] = 'Informe um CPF válido (usado na nota fiscal e no envio).';
        }
        $phone = BrazilianDocument::phone((string) $input['phone']);
        if ($phone === null) {
            $errors['phone'] = 'Informe um celular ou telefone com DDD.';
        }
        $zip = ZipCode::normalize((string) $input['zip_code']);
        $zipState = $zip === null ? null : ZipCode::state($zip);
        if ($zip === null || $zipState === null) {
            $errors['zip_code'] = 'Informe um CEP válido.';
        } elseif ($input['state'] !== $zipState) {
            $errors['state'] = "O CEP informado é de {$zipState}.";
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $email = $customerId !== null && $input['logged_in'] ? (string) $input['account_email'] : AuthService::normalizeEmail((string) $input['email']);
        $complement = trim((string) $input['complement']);

        return [
            ['name' => (string) $input['name'], 'email' => $email, 'cpf' => (string) $cpf, 'phone' => (string) $phone],
            [
                'recipient_name' => (string) ($input['recipient_name'] ?: $input['name']),
                'zip_code' => (string) $zip,
                'street' => (string) $input['street'],
                'number' => (string) $input['number'],
                'complement' => $complement === '' ? null : $complement,
                'district' => (string) $input['district'],
                'city' => (string) $input['city'],
                'state' => (string) $input['state'],
            ],
        ];
    }

    /**
     * Com conta: o próprio cliente. Sem conta: reaproveita o cadastro de visitante com o
     * mesmo e-mail (sem login não há como ver pedidos antigos por ele — só pelo link de cada pedido).
     *
     * @param array{name: string, email: string, cpf: string, phone: string} $contact
     */
    private function resolveCustomer(?int $customerId, array $contact): int
    {
        if ($customerId === null) {
            $guest = $this->customers->findGuestByEmail($contact['email']);
            $customerId = $guest === null ? $this->customers->create(null, $contact['name'], $contact['email']) : (int) $guest['id'];
        }
        $this->customers->updateContact($customerId, $contact['name'], $contact['cpf'], $contact['phone']);

        return $customerId;
    }

    /** Peso embalado: peso da embalagem, ou do produto + embalagem, ou o padrão. */
    private function weight(array $summary): int
    {
        $default = (int) $this->config->get('shipping.default_package_weight_g', 1000);
        $packaging = (int) $this->config->get('shipping.packaging_weight_g', 200);
        $total = 0;
        foreach ($summary['items'] as $item) {
            $unit = match (true) {
                !empty($item['package_weight_g']) => (int) $item['package_weight_g'],
                !empty($item['weight_g']) => (int) $item['weight_g'] + $packaging,
                default => $default,
            };
            $total += $unit * (int) $item['quantity'];
        }

        return $total;
    }
}
