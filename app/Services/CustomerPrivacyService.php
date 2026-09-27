<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Config;
use GNesting\Core\Database;
use GNesting\Enums\OrderStatus;
use GNesting\Repositories\AddressRepository;
use GNesting\Repositories\CustomerRepository;
use GNesting\Repositories\OrderRepository;
use GNesting\Repositories\PrivacyRepository;

/**
 * Direitos do titular (LGPD, art. 18), executados pelo proprietário a pedido do cliente:
 *
 * - Exportação: todos os dados do cadastro em JSON (cadastro, conta, endereços, pedidos com itens).
 * - Anonimização: apaga conta, endereços, carrinhos e contato. Os pedidos ficam (obrigação fiscal e
 *   Código de Defesa do Consumidor, art. 16 da LGPD); os mais antigos que o prazo de guarda
 *   (security.privacy.order_retention_years) também têm os dados pessoais apagados.
 *
 * Nada de dado pessoal vai para a auditoria: só a ação e as contagens.
 */
final class CustomerPrivacyService
{
    public function __construct(
        private readonly Database $db,
        private readonly PrivacyRepository $privacy,
        private readonly CustomerRepository $customers,
        private readonly AddressRepository $addresses,
        private readonly OrderRepository $orders,
        private readonly AuditService $audit,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws BusinessRuleException
     */
    public function export(int $customerId): array
    {
        $customer = $this->customers->adminFind($customerId) ?? throw new BusinessRuleException('Cliente não encontrado.');
        if ($customer['anonymized_at'] !== null) {
            throw new BusinessRuleException('Este cadastro foi anonimizado: não há dados pessoais para exportar.');
        }

        $orders = [];
        foreach ($this->privacy->orderIds($customerId) as $orderId) {
            $order = $this->orders->find($orderId);
            if ($order === null) {
                continue;
            }
            $orders[] = [
                'numero' => $order['number'],
                'data' => $order['placed_at'],
                'situacao' => $order['status'],
                'pagamento' => $order['payment_status'],
                'total_centavos' => (int) $order['total_cents'],
                'nome' => $order['customer_name'],
                'email' => $order['customer_email'],
                'telefone' => $order['customer_phone'],
                'cpf' => $order['customer_cpf'],
                'entrega' => [
                    'destinatario' => $order['ship_recipient'], 'cep' => $order['ship_zip_code'], 'rua' => $order['ship_street'],
                    'numero' => $order['ship_number'], 'complemento' => $order['ship_complement'], 'bairro' => $order['ship_district'],
                    'cidade' => $order['ship_city'], 'uf' => $order['ship_state'],
                ],
                'itens' => array_map(static fn (array $item): array => [
                    'produto' => $item['product_name'],
                    'variacao' => $item['variant_name'],
                    'quantidade' => (int) $item['quantity'],
                    'personalizacao' => array_map(static fn (array $p): array => [
                        'campo' => $p['label'], 'valor' => $p['value_label'] ?? $p['value_text'],
                    ], $item['personalization']),
                ], $this->orders->items($orderId)),
            ];
        }

        $this->audit->record(AuditService::EXPORT, 'customer', $customerId, null, ['pedidos' => count($orders)]);

        return [
            'gerado_em_utc' => now_utc(),
            'controlador' => 'G-Nesting',
            'cadastro' => [
                'nome' => $customer['name'],
                'email' => $customer['email'],
                'cpf' => $customer['cpf'],
                'telefone' => $customer['phone'],
                'aceita_whatsapp' => (bool) $customer['whatsapp_opt_in'],
                'aceita_marketing' => (bool) $customer['marketing_opt_in'],
                'cliente_desde' => $customer['created_at'],
                'observacoes_da_loja' => $customer['notes'],
            ],
            'conta' => $customer['user_id'] ? $this->privacy->account((int) $customer['user_id']) : null,
            'enderecos' => array_map(static fn (array $a): array => array_intersect_key($a, array_flip([
                'label', 'recipient_name', 'zip_code', 'street', 'number', 'complement', 'district', 'city', 'state',
            ])), $this->addresses->forCustomer($customerId)),
            'pedidos' => $orders,
        ];
    }

    /**
     * @return array{orders_kept: int, orders_anonymized: int, retention_years: int}
     * @throws BusinessRuleException
     */
    public function anonymize(int $customerId): array
    {
        $years = max(1, (int) $this->config->get('security.privacy.order_retention_years', 5));

        return $this->db->transaction(function () use ($customerId, $years): array {
            $customer = $this->privacy->lockCustomer($customerId) ?? throw new BusinessRuleException('Cliente não encontrado.');
            if ($customer['anonymized_at'] !== null) {
                throw new BusinessRuleException('Este cadastro já foi anonimizado.');
            }
            $final = array_values(array_map(
                static fn (OrderStatus $s): string => $s->value,
                array_filter(OrderStatus::cases(), static fn (OrderStatus $s): bool => $s->isFinal()),
            ));
            if ($this->privacy->openOrdersCount($customerId, $final) > 0) {
                throw new BusinessRuleException('Há pedidos em andamento. Conclua ou cancele esses pedidos antes de anonimizar o cadastro.');
            }

            $total = count($this->privacy->orderIds($customerId));
            $anonymizedOrders = $this->privacy->anonymizeOrdersBefore($customerId, now_utc("-{$years} years"));
            $this->privacy->deleteCustomerData($customerId);
            $this->privacy->anonymizeCustomer($customerId);
            if ($customer['user_id'] !== null) {
                $this->privacy->deleteCustomerUser((int) $customer['user_id']);
            }

            $result = ['orders_kept' => $total - $anonymizedOrders, 'orders_anonymized' => $anonymizedOrders, 'retention_years' => $years];
            $this->audit->record(AuditService::ANONYMIZE, 'customer', $customerId, null, $result);

            return $result;
        });
    }
}
