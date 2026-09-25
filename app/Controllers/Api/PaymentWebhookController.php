<?php

declare(strict_types=1);

namespace GNesting\Controllers\Api;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Services\PaymentService;

/**
 * POST /webhooks/pagamento/{provedor} — sem CSRF (config/security.php), protegido pela
 * assinatura do provedor. Responde rápido e sem detalhes: o provedor reenvia se não for 2xx.
 */
final class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function handle(Request $request): Response
    {
        if ($request->param('provedor') !== $this->payments->providerName()) {
            throw HttpException::notFound();
        }
        $status = $this->payments->handleWebhook($request);

        return Response::json(['ok' => $status < 300], $status);
    }
}
