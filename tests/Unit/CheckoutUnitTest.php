<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Core\Config;
use GNesting\Core\Request;
use GNesting\Enums\PaymentStatus;
use GNesting\Helpers\BrazilianDocument;
use GNesting\Helpers\ZipCode;
use GNesting\Services\Payment\HttpClient;
use GNesting\Services\Payment\MercadoPagoGateway;
use GNesting\Services\Shipping\TableShippingCalculator;
use PHPUnit\Framework\TestCase;

/** CEP, CPF/telefone, frete por tabela e adaptador do Mercado Pago (sem rede). */
final class CheckoutUnitTest extends TestCase
{
    public function testZipCodeNormalizationStateAndRegion(): void
    {
        self::assertSame('40140110', ZipCode::normalize('40.140-110'));
        self::assertNull(ZipCode::normalize('4014011'));
        self::assertNull(ZipCode::normalize('00000-000'));
        self::assertSame('40140-110', ZipCode::format('40140110'));

        foreach (['01310100' => 'SP', '20040002' => 'RJ', '40140110' => 'BA', '69005000' => 'AM', '69301000' => 'RR',
                     '70040010' => 'DF', '72800000' => 'GO', '73000000' => 'DF', '76801000' => 'RO', '88010000' => 'SC', '90010000' => 'RS'] as $zip => $uf) {
            self::assertSame($uf, ZipCode::state((string) $zip), (string) $zip);
        }
        self::assertSame('NE', ZipCode::region('BA'));
        self::assertSame('SE', ZipCode::region('SP'));
        self::assertCount(27, ZipCode::states());
    }

    public function testCpfAndPhone(): void
    {
        self::assertSame('52998224725', BrazilianDocument::cpf('529.982.247-25'));
        foreach (['529.982.247-24', '111.111.111-11', '123', ''] as $invalid) {
            self::assertNull(BrazilianDocument::cpf($invalid), $invalid);
        }
        self::assertSame('529.982.247-25', BrazilianDocument::formatCpf('52998224725'));

        self::assertSame('5571999998888', BrazilianDocument::phone('(71) 99999-8888'));
        self::assertSame('5571999998888', BrazilianDocument::phone('+55 71 99999-8888'));
        self::assertSame('557133334444', BrazilianDocument::phone('71 3333-4444'));
        foreach (['99999-8888', '(71) 89999-8888', '(01) 99999-8888', 'abc'] as $invalid) {
            self::assertNull(BrazilianDocument::phone($invalid), $invalid);
        }
        self::assertSame('(71) 99999-8888', BrazilianDocument::formatPhone('5571999998888'));
    }

    private function shippingConfig(array $overrides = []): Config
    {
        return new Config(['shipping' => $overrides + [
            'origin_state' => 'BA',
            'free_shipping_min_cents' => null,
            'free_shipping_service' => 'economico',
            'services' => ['economico' => ['carrier' => 'Correios', 'label' => 'PAC'], 'expresso' => ['carrier' => 'Correios', 'label' => 'SEDEX']],
            'table' => [
                'local' => ['economico' => [1000, 200, 3], 'expresso' => [2000, 400, 1]],
                'SE' => ['economico' => [3000, 600, 8], 'expresso' => [5000, 1000, 4]],
            ],
            'pickup' => ['enabled' => false, 'label' => 'Retirada', 'days' => 0],
        ]]);
    }

    public function testTableShippingByBandWeightAndFreeShipping(): void
    {
        $calculator = new TableShippingCalculator($this->shippingConfig());

        $local = $calculator->quote('40140110', 900, 10000); // BA = origem
        self::assertSame(['economico', 'expresso'], array_map(fn ($o) => $o->code, $local), 'Mais barato primeiro');
        self::assertSame(1000, $local[0]->priceCents);

        $heavy = $calculator->quote('01310100', 2600, 10000); // SP, 2,6 kg → +2 kg adicionais
        self::assertSame(3000 + 2 * 600, $heavy[0]->priceCents);
        self::assertSame(8, $heavy[0]->days);

        self::assertSame([], $calculator->quote('12', 1000, 0), 'CEP inválido');

        $free = new TableShippingCalculator($this->shippingConfig(['free_shipping_min_cents' => 30000,
            'pickup' => ['enabled' => true, 'label' => 'Retirada no ateliê', 'days' => 0]]));
        $options = $free->quote('01310100', 1000, 30000);
        self::assertSame(0, $options[0]->priceCents);
        self::assertContains('retirada', array_map(fn ($o) => $o->code, $options));
        $belowThreshold = array_column(array_map(fn ($o) => $o->toArray(), $free->quote('01310100', 1000, 29999)), 'price_cents', 'code');
        self::assertSame(3000, $belowThreshold['economico'], 'Abaixo do mínimo, o econômico é cobrado');
    }

    /** @param list<array{status: int, body: string}> $responses */
    private function fakeHttp(array $responses, array &$calls): HttpClient
    {
        return new class ($responses, $calls) implements HttpClient {
            public function __construct(private array $responses, private array &$calls)
            {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                $this->calls[] = compact('method', 'url', 'headers', 'body');

                return array_shift($this->responses) ?? ['status' => 500, 'body' => ''];
            }
        };
    }

    public function testMercadoPagoCreatesPreferenceWithItemsShippingAndReference(): void
    {
        $calls = [];
        $gateway = new MercadoPagoGateway($this->fakeHttp([['status' => 201, 'body' => '{"id":"pref-1","init_point":"https://mp.test/checkout/pref-1"}']], $calls), 'TOKEN', 'secret');

        $session = $gateway->createCheckout(
            ['number' => 'GN-2026-000007', 'customer_name' => 'Ana', 'customer_email' => 'ana@x.test', 'shipping_cents' => 2490, 'shipping_service' => 'PAC', 'total_cents' => 28470],
            [['sku' => 'REL-1', 'product_name' => 'Relógio', 'variant_name' => 'Preto', 'unit_price_cents' => 12990, 'personalization_cents' => 0, 'quantity' => 2]],
            ['success' => 'https://loja/ok', 'pending' => 'https://loja/p', 'failure' => 'https://loja/f', 'notification' => 'https://loja/webhooks/pagamento/mercadopago'],
        );

        self::assertSame(['reference' => 'pref-1', 'url' => 'https://mp.test/checkout/pref-1'], $session);
        self::assertSame('https://api.mercadopago.com/checkout/preferences', $calls[0]['url']);
        self::assertSame('Bearer TOKEN', $calls[0]['headers']['Authorization']);
        $payload = json_decode($calls[0]['body'], true);
        self::assertSame('GN-2026-000007', $payload['external_reference']);
        self::assertSame(129.9, $payload['items'][0]['unit_price']);
        self::assertSame('frete', $payload['items'][1]['id']);
        $sum = array_sum(array_map(fn ($i) => (int) round($i['unit_price'] * 100) * $i['quantity'], $payload['items']));
        self::assertSame(28470, $sum, 'Itens + frete = total do pedido');
    }

    public function testMercadoPagoPaymentMapping(): void
    {
        $calls = [];
        $gateway = new MercadoPagoGateway($this->fakeHttp([
            ['status' => 200, 'body' => '{"id":123,"status":"approved","external_reference":"GN-2026-000007","transaction_amount":284.7,"payment_type_id":"bank_transfer","payment_method_id":"pix"}'],
            ['status' => 200, 'body' => '{"id":124,"status":"rejected","status_detail":"cc_rejected_insufficient_amount","external_reference":"GN-2026-000007","transaction_amount":284.7,"payment_type_id":"credit_card","payment_method_id":"visa","installments":3,"card":{"last_four_digits":"4242"}}'],
            ['status' => 404, 'body' => '{}'],
        ], $calls), 'TOKEN', 'secret');

        $pix = $gateway->fetchPayment('123');
        self::assertSame(PaymentStatus::Paid, $pix->status);
        self::assertSame(28470, $pix->amountCents);
        self::assertSame('pix', $pix->method);

        $card = $gateway->fetchPayment('124');
        self::assertSame(PaymentStatus::Failed, $card->status);
        self::assertSame('credit_card', $card->method);
        self::assertSame('4242', $card->cardLast4);
        self::assertSame(3, $card->installments);
        self::assertSame('cc_rejected_insufficient_amount', $card->failureReason);

        self::assertNull($gateway->fetchPayment('999'));
        self::assertNull($gateway->fetchPayment('../x'), 'Id malformado nem chega à API');
        self::assertCount(3, $calls);
    }

    public function testMercadoPagoWebhookSignature(): void
    {
        $calls = [];
        $gateway = new MercadoPagoGateway($this->fakeHttp([], $calls), 'TOKEN', 'segredo');
        $ts = '1742505638683';
        $signature = hash_hmac('sha256', "id:123;request-id:req-1;ts:{$ts};", 'segredo');
        $request = fn (string $sig) => new Request('POST', '/webhooks/pagamento/mercadopago', ['data_id' => '123', 'type' => 'payment'], [], [],
            ['HTTP_X_SIGNATURE' => "ts={$ts},v1={$sig}", 'HTTP_X_REQUEST_ID' => 'req-1'], [], '{"id":987,"action":"payment.updated","type":"payment","data":{"id":"123"}}');

        $valid = $gateway->parseWebhook($request($signature));
        self::assertTrue($valid['signature_valid']);
        self::assertSame('123', $valid['payment_id']);
        self::assertSame('987', $valid['event_id']);

        self::assertFalse($gateway->parseWebhook($request(str_repeat('0', 64)))['signature_valid']);
        self::assertFalse((new MercadoPagoGateway($this->fakeHttp([], $calls), 'TOKEN', ''))->parseWebhook($request($signature))['signature_valid'], 'Sem segredo configurado, nada é aceito');
    }
}
