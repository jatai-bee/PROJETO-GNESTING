<?php

declare(strict_types=1);

namespace GNesting\Tests\Support;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use RuntimeException;

/** Rotas usadas apenas nos testes do Kernel. */
final class TestController extends Controller
{
    public function ok(Request $request): Response
    {
        return Response::html('ok');
    }

    public function explode(Request $request): Response
    {
        throw new RuntimeException('Falha interna com segredo: senha=123 em C:\\app\\arquivo.php');
    }
}
