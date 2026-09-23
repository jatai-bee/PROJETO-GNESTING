<?php

declare(strict_types=1);

/*
 * Endpoints JSON e webhooks (etapas 7 e 10).
 * Webhooks ficam sob /webhooks/ — isentos de CSRF (config/security.php),
 * protegidos por assinatura do provedor.
 */

use GNesting\Core\Router;

return static function (Router $r): void {
};
