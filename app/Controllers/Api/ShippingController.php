<?php

declare(strict_types=1);

namespace GNesting\Controllers\Api;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Helpers\ZipCode;
use GNesting\Services\Auth\TooManyAttemptsException;
use GNesting\Services\CheckoutService;
use GNesting\Services\RateLimiter;
use GNesting\Services\Shipping\ShippingOption;

/** POST /api/frete/cotar — cotação para o carrinho atual (JSON; CSRF pelo cabeçalho X-CSRF-Token). */
final class ShippingController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function quote(Request $request): Response
    {
        $key = 'shipping:ip:' . $request->ip();
        [$max, $window] = config('security.rate_limits.shipping', [60, 60]);
        try {
            $this->rateLimiter->ensureNotBlocked($key);
        } catch (TooManyAttemptsException $e) {
            throw HttpException::tooManyRequests($e->retryAfterSeconds());
        }
        $this->rateLimiter->hit($key, (int) $max, (int) $window);

        $zip = ZipCode::normalize($request->string('cep'));
        if ($zip === null || ZipCode::state($zip) === null) {
            return Response::json(['message' => 'CEP inválido.', 'options' => []], 422);
        }

        return Response::json([
            'cep' => ZipCode::format($zip),
            'state' => ZipCode::state($zip),
            'options' => array_map(static fn (ShippingOption $o): array => $o->toArray() + [
                'price' => money($o->priceCents),
            ], $this->checkout->quote($zip)),
        ]);
    }
}
