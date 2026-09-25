<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Auth;
use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Logger;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\Session;
use GNesting\Core\ValidationException;
use GNesting\Core\Validator;
use GNesting\Helpers\ZipCode;
use GNesting\Repositories\AddressRepository;
use GNesting\Repositories\CustomerRepository;
use GNesting\Repositories\OrderRepository;
use GNesting\Services\Auth\TooManyAttemptsException;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\CheckoutService;
use GNesting\Services\OrderLink;
use GNesting\Services\PaymentService;
use GNesting\Services\RateLimiter;
use Throwable;

/**
 * Checkout (com ou sem conta), página do pedido e retomada do pagamento.
 *
 * Acesso a um pedido: cliente logado dono do pedido, OU link privado (?chave=),
 * OU o mesmo navegador que acabou de comprar (sessão). Caso contrário, 404 —
 * não se revela que o número existe.
 */
final class CheckoutController extends Controller
{
    private const SESSION_ORDERS = 'orders.recent';

    private const LABELS = [
        'name' => 'Nome completo', 'email' => 'E-mail', 'cpf' => 'CPF', 'phone' => 'Telefone',
        'zip_code' => 'CEP', 'street' => 'Rua', 'number' => 'Número', 'complement' => 'Complemento',
        'district' => 'Bairro', 'city' => 'Cidade', 'state' => 'UF', 'recipient_name' => 'Quem recebe',
        'shipping_code' => 'Entrega',
    ];

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PaymentService $payments,
        private readonly OrderRepository $orders,
        private readonly CustomerRepository $customers,
        private readonly AddressRepository $addresses,
        private readonly RateLimiter $rateLimiter,
        private readonly Auth $auth,
        private readonly Session $session,
        private readonly Logger $logger,
        private readonly OrderLink $links,
    ) {
    }

    public function show(Request $request): Response
    {
        try {
            $summary = $this->checkout->cartForCheckout();
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/carrinho');
        }

        $customer = $this->auth->customer();
        $contact = $customer === null ? null : $this->customers->findContact((int) $customer['customer_id']);
        $saved = $customer === null ? [] : $this->addresses->forCustomer((int) $customer['customer_id']);

        // Frete: CEP digitado (após "calcular" ou erro) ou o do endereço salvo padrão
        $old = $this->session->getFlash('old', []);
        $zip = (string) ($old['zip_code'] ?? ($saved[0]['zip_code'] ?? ''));
        $options = $zip === '' ? [] : $this->checkout->quote($zip, $summary);

        return $this->render('store/checkout/show', [
            'title' => 'Finalizar compra | G-Nesting',
            'noindex' => true,
            'cart' => $summary,
            'customer' => $customer,
            'contact' => $contact,
            'savedAddresses' => $saved,
            'shippingOptions' => $options,
            'quotedZip' => ZipCode::normalize($zip),
            'states' => ZipCode::states(),
        ]);
    }

    public function place(Request $request): Response
    {
        // "Calcular frete" sem JavaScript: volta ao formulário com o CEP e as opções
        $customer = $this->auth->customer();
        if ($request->string('action') === 'quote') {
            $old = $this->input($request, $customer);
            unset($old['logged_in'], $old['account_email']);
            $this->flash('old', array_map('strval', $old));

            return $this->redirect('/checkout');
        }

        $key = 'checkout:ip:' . $request->ip();
        try {
            $this->rateLimiter->ensureNotBlocked($key);
        } catch (TooManyAttemptsException $e) {
            throw HttpException::tooManyRequests($e->retryAfterSeconds());
        }

        $rules = [
            'name' => 'required|min:3|max:120',
            'cpf' => 'required|max:20',
            'phone' => 'required|max:25',
            'zip_code' => 'required|max:10',
            'street' => 'required|max:160',
            'number' => 'required|max:20',
            'complement' => 'max:80',
            'district' => 'required|max:80',
            'city' => 'required|max:80',
            'state' => 'required|in:' . implode(',', ZipCode::states()),
            'recipient_name' => 'max:120',
            'shipping_code' => 'required|max:30',
        ];
        if ($customer === null) {
            $rules['email'] = 'required|email|max:190';
        }

        try {
            // Valida o endereço já resolvido (se um endereço salvo foi escolhido, vale ele)
            $input = $this->input($request, $customer);
            Validator::validate(array_map('strval', array_intersect_key($input, $rules)), $rules, self::LABELS);
            $placed = $this->checkout->place($input, $customer['customer_id'] ?? null);
        } catch (ValidationException $e) {
            $this->flash('errors', $e->errors());
            $this->flash('old', $this->formData($request));

            return $this->redirect('/checkout');
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/carrinho');
        }

        $this->rateLimiter->hit($key, (int) config('payment.max_orders_per_hour', 10), 3600);
        $this->remember($placed['number']);

        return $this->goToPayment($placed['order_id'], $placed['number'], $placed['token']);
    }

    public function confirmation(Request $request): Response
    {
        $order = $this->authorizedOrder($request);

        // Retorno do provedor: reconsulta o pagamento (a URL nunca confirma nada sozinha)
        $returned = $request->queryString('payment_id', 30) ?: $request->queryString('collection_id', 30);
        if ($returned !== '' && $order['status'] === 'awaiting_payment') {
            try {
                $this->payments->sync($returned, 'return');
                $order = $this->orders->find((int) $order['id']) ?? $order;
            } catch (Throwable $e) {
                $this->logger->warning('Não foi possível consultar o pagamento no retorno', ['order' => $order['number'], 'error' => $e->getMessage()]);
            }
        }

        return $this->render('store/checkout/confirmation', [
            'title' => 'Pedido ' . $order['number'] . ' | G-Nesting',
            'noindex' => true,
            'order' => $order,
            'items' => $this->orders->items((int) $order['id']),
            'payments' => $this->orders->payments((int) $order['id']),
            'history' => $this->orders->history((int) $order['id']),
            'shipment' => $this->orders->latestShipment((int) $order['id']),
            'messages' => $this->orders->notes((int) $order['id'], 'customer'),
            'accessKey' => $request->queryString('chave', 64),
            'returnStatus' => $request->queryString('status', 20),
        ]);
    }

    /** "Pagar agora" num pedido que ainda aguarda pagamento. */
    public function pay(Request $request): Response
    {
        $order = $this->authorizedOrder($request, $request->string('chave'));

        return $this->goToPayment((int) $order['id'], (string) $order['number'], $request->string('chave'));
    }

    private function goToPayment(int $orderId, string $number, string $token): Response
    {
        $path = '/pedido/' . $number . '/confirmacao';
        $key = $token !== '' ? ['chave' => $token] : [];
        $orderUrl = $path . ($key === [] ? '' : '?' . http_build_query($key));
        $back = static fn (string $status): string => absolute_url($path) . '?' . http_build_query($key + ['status' => $status]);

        try {
            $url = $this->payments->checkoutUrl($orderId, [
                'success' => $back('aprovado'),
                'pending' => $back('pendente'),
                'failure' => $back('recusado'),
                'notification' => absolute_url('/webhooks/pagamento/' . $this->payments->providerName()),
            ]);
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect($orderUrl);
        } catch (Throwable $e) {
            $this->logger->error('Falha ao abrir o checkout de pagamento', ['order' => $number, 'error' => $e->getMessage()]);
            $this->flash('error', 'Seu pedido foi registrado, mas não conseguimos abrir o pagamento agora. Tente "Pagar agora" em instantes.');

            return $this->redirect($orderUrl);
        }

        // Checkout hospedado: externo (Mercado Pago) ou a página simulada local
        return Response::redirect($url);
    }

    /** @return array<string, mixed> */
    private function authorizedOrder(Request $request, ?string $key = null): array
    {
        $order = $this->orders->findByNumber((string) $request->param('numero')) ?? throw HttpException::notFound();

        $key ??= $request->queryString('chave', 64);
        $customer = $this->auth->customer();
        $recent = $this->session->get(self::SESSION_ORDERS, []);
        $allowed = ($customer !== null && (int) $customer['customer_id'] === (int) $order['customer_id'])
            || $this->links->matches($order, $key)
            || (is_array($recent) && isset($recent[$order['number']]));

        if (!$allowed) {
            throw HttpException::notFound();
        }

        return $order;
    }

    /** Este navegador acabou de comprar: pode abrir o pedido sem a chave (até 10 pedidos). */
    private function remember(string $number): void
    {
        $recent = $this->session->get(self::SESSION_ORDERS, []);
        $recent = is_array($recent) ? $recent : [];
        $recent[$number] = true;
        $this->session->set(self::SESSION_ORDERS, array_slice($recent, -10, null, true));
    }

    /** @return array<string, string> */
    private function formData(Request $request): array
    {
        $data = [];
        foreach (['name', 'email', 'cpf', 'phone', 'zip_code', 'street', 'number', 'complement', 'district', 'city', 'state', 'recipient_name', 'shipping_code', 'address_id', 'quoted_zip'] as $field) {
            $data[$field] = $request->string($field);
        }
        $data['state'] = strtoupper($data['state']);
        if ($request->boolean('save_address')) {
            $data['save_address'] = '1';
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|null $customer
     * @return array<string, mixed>
     */
    private function input(Request $request, ?array $customer): array
    {
        $data = $this->formData($request);

        // Endereço salvo escolhido: vale o que está gravado (só se for deste cliente)
        if ($customer !== null && $data['address_id'] !== '') {
            $saved = $this->addresses->find((int) $customer['customer_id'], (int) $data['address_id']);
            if ($saved !== null) {
                foreach (['zip_code', 'street', 'number', 'complement', 'district', 'city', 'state'] as $field) {
                    $data[$field] = (string) $saved[$field];
                }
                $data['recipient_name'] = (string) $saved['recipient_name'];
            }
        }

        return $data + [
            'logged_in' => $customer !== null,
            'account_email' => $customer['email'] ?? '',
            'save_address' => $request->boolean('save_address'),
        ];
    }
}
