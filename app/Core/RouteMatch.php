<?php

declare(strict_types=1);

namespace GNesting\Core;

final class RouteMatch
{
    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string>                      $middleware
     * @param array<string, string>             $params
     */
    public function __construct(
        public readonly array $handler,
        public readonly array $middleware,
        public readonly array $params,
    ) {
    }
}
