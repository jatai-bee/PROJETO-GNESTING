<?php

declare(strict_types=1);

namespace GNesting\Controllers;

use GNesting\Core\Config;
use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Services\Operations\HealthCheck;

/**
 * GET /saude — para monitores externos (UptimeRobot etc.). Sem sessão nem cookies.
 *
 * Sem token: {"status":"ok"|"atencao"|"falha"} — 503 só em falha crítica (banco, disco).
 * Com ?token=HEALTH_TOKEN: também o detalhe de cada verificação.
 */
final class HealthController extends Controller
{
    public function __construct(
        private readonly HealthCheck $health,
        private readonly Config $config,
    ) {
    }

    public function show(Request $request): Response
    {
        $result = $this->health->run();
        $token = (string) $this->config->get('operations.health_token', '');
        $offered = $request->queryString('token', 200);
        $detailed = $token !== '' && $offered !== '' && hash_equals($token, $offered);

        return Response::json($detailed ? $result : ['status' => $result['status']], $result['status'] === HealthCheck::FAILURE ? 503 : 200)
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
