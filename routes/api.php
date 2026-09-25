<?php

declare(strict_types=1);

/*
 * Endpoints JSON e webhooks.
 * Webhooks ficam sob /webhooks/ — isentos de CSRF (config/security.php),
 * protegidos por assinatura do provedor.
 */

use GNesting\Controllers\Api\PaymentWebhookController;
use GNesting\Controllers\Api\ShippingController;
use GNesting\Core\Router;

return static function (Router $r): void {
    $r->post('/api/frete/cotar', [ShippingController::class, 'quote']);
    $r->post('/webhooks/pagamento/{provedor:[a-z]+}', [PaymentWebhookController::class, 'handle']);
};
