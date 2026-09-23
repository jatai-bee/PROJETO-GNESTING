<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/', ['Home', 'index']);
        $this->router->get('/produto/{slug}', ['Product', 'show']);
        $this->router->group(['prefix' => '/admin', 'middleware' => ['admin']], function (Router $r): void {
            $r->get('/', ['Dashboard', 'index']);
            $r->post('/pedidos/{id:\d+}/status', ['Order', 'status'], ['role:manager,production']);
        });
    }

    public function testMatchesRouteWithParameters(): void
    {
        $match = $this->router->match('GET', '/produto/relogio-geometrico');

        self::assertSame(['Product', 'show'], $match->handler);
        self::assertSame(['slug' => 'relogio-geometrico'], $match->params);
        self::assertSame([], $match->middleware);
    }

    public function testGroupPrefixAndMiddlewareAreCombined(): void
    {
        self::assertSame(['admin'], $this->router->match('GET', '/admin')->middleware);

        $match = $this->router->match('POST', '/admin/pedidos/42/status');
        self::assertSame(['admin', 'role:manager,production'], $match->middleware);
        self::assertSame(['id' => '42'], $match->params);
    }

    public function testNotFound(): void
    {
        $this->expectExceptionObject(HttpException::notFound());
        $this->router->match('GET', '/admin/pedidos/abc/status');
    }

    public function testMethodNotAllowed(): void
    {
        try {
            $this->router->match('POST', '/');
            self::fail('Deveria lançar 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status());
            self::assertSame('GET', $e->headers()['Allow']);
        }
    }

    public function testPathNormalization(): void
    {
        self::assertSame('/', Request::normalizePath(''));
        self::assertSame('/entrar', Request::normalizePath('/entrar/'));
        self::assertSame('/entrar', Request::normalizePath('/gnesting/entrar', '/gnesting'));
        self::assertSame('/', Request::normalizePath('/gnesting/', '/gnesting'));
        self::assertSame('/gnesting-x', Request::normalizePath('/gnesting-x', '/gnesting'));
        self::assertSame('/produto/relógio', Request::normalizePath('/produto/rel%C3%B3gio'));
    }
}
