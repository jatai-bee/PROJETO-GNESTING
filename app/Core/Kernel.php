<?php

declare(strict_types=1);

namespace GNesting\Core;

use Closure;
use GNesting\Middleware\Middleware;
use RuntimeException;
use Throwable;

/**
 * Pipeline HTTP: rota → middleware (globais + da rota) → controller.
 * Qualquer exceção vira uma resposta segura (ErrorHandler) e toda resposta
 * recebe os cabeçalhos de segurança.
 */
final class Kernel
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly Config $config,
        private readonly ErrorHandler $errors,
        private readonly SecurityHeaders $securityHeaders,
        private readonly AuditContext $auditContext,
    ) {
    }

    public function handle(Request $request): Response
    {
        $this->auditContext->reset($request->ip(), $request->userAgent());

        try {
            $match = $this->router->match($request->method(), $request->path());
            $request->setRouteParams($match->params);

            $stack = [...$this->config->get('middleware.global', []), ...$match->middleware];
            $response = $this->pipeline($stack, fn (Request $r): Response => $this->callController($match, $r))($request);
        } catch (Throwable $e) {
            $response = $this->errors->toResponse($e, $request);
        }

        return $this->securityHeaders->apply($response, $request);
    }

    /**
     * @param list<string> $stack ex.: ['session', 'csrf', 'role:manager,production']
     * @param Closure(Request): Response $core
     * @return Closure(Request): Response
     */
    private function pipeline(array $stack, Closure $core): Closure
    {
        $aliases = $this->config->get('middleware.aliases', []);
        $next = $core;

        foreach (array_reverse($stack) as $entry) {
            [$alias, $paramString] = array_pad(explode(':', $entry, 2), 2, '');
            /** @var class-string $class */
            $class = $aliases[$alias] ?? throw new RuntimeException("Middleware desconhecido: {$alias}");
            $params = $paramString === '' ? [] : explode(',', $paramString);

            $next = function (Request $request) use ($class, $params, $next): Response {
                $middleware = $this->container->get($class);
                if (!$middleware instanceof Middleware) {
                    throw new RuntimeException("{$class} não implementa Middleware.");
                }

                return $middleware->handle($request, $next, ...$params);
            };
        }

        return $next;
    }

    private function callController(RouteMatch $match, Request $request): Response
    {
        [$class, $method] = $match->handler;
        $controller = $this->container->get($class);
        $response = $controller->{$method}($request);

        if (!$response instanceof Response) {
            throw new RuntimeException("{$class}::{$method} deve retornar Response.");
        }

        return $response;
    }
}
