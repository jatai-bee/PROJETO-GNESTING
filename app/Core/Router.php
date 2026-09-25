<?php

declare(strict_types=1);

namespace GNesting\Core;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * Registro de rotas sobre o FastRoute, com grupos (prefixo + middleware).
 *
 *   $r->get('/produto/{slug}', [ProductController::class, 'show']);
 *   $r->group(['prefix' => '/admin', 'middleware' => ['admin']], function (Router $r) { ... });
 */
final class Router
{
    /** @var list<array{methods: list<string>, path: string, handler: array{0: class-string, 1: string}, middleware: list<string>}> */
    private array $routes = [];

    /** @var list<array{prefix: string, middleware: list<string>}> */
    private array $groupStack = [];

    private ?Dispatcher $dispatcher = null;

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add(['GET'], $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add(['POST'], $path, $handler, $middleware);
    }

    /**
     * @param list<string> $methods
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function add(array $methods, string $path, array $handler, array $middleware = []): void
    {
        $prefix = '';
        $groupMiddleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $groupMiddleware = [...$groupMiddleware, ...$group['middleware']];
        }

        $fullPath = '/' . trim($prefix . '/' . trim($path, '/'), '/');

        $this->routes[] = [
            'methods' => $methods,
            'path' => $fullPath,
            'handler' => $handler,
            'middleware' => [...$groupMiddleware, ...$middleware],
        ];
        $this->dispatcher = null;
    }

    /**
     * @param array{prefix?: string, middleware?: list<string>} $attributes
     * @param callable(Router): void $routes
     */
    public function group(array $attributes, callable $routes): void
    {
        $this->groupStack[] = [
            'prefix' => isset($attributes['prefix']) ? '/' . trim($attributes['prefix'], '/') : '',
            'middleware' => $attributes['middleware'] ?? [],
        ];
        try {
            $routes($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /** @throws HttpException 404 ou 405 */
    public function match(string $method, string $path): RouteMatch
    {
        $result = $this->dispatcher()->dispatch($method, $path);

        return match ($result[0]) {
            Dispatcher::FOUND => new RouteMatch(
                $this->routes[$result[1]]['handler'],
                $this->routes[$result[1]]['middleware'],
                array_map('strval', $result[2]),
            ),
            Dispatcher::METHOD_NOT_ALLOWED => throw HttpException::methodNotAllowed($result[1]),
            default => throw HttpException::notFound(),
        };
    }

    /** @return list<array{methods: list<string>, path: string, middleware: list<string>}> */
    public function routes(): array
    {
        return array_map(fn (array $r) => ['methods' => $r['methods'], 'path' => $r['path'], 'middleware' => $r['middleware']], $this->routes);
    }

    private function dispatcher(): Dispatcher
    {
        return $this->dispatcher ??= simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes as $index => $route) {
                $collector->addRoute($route['methods'], $route['path'], $index);
            }
        });
    }
}
