<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;

final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        // Home provisória: vitrine completa na etapa 4.
        return $this->render('store/home', [
            'title' => 'G-Nesting — Objetos que transformam espaços.',
            'metaDescription' => 'Objetos de design produzidos com fabricação digital: relógios, painéis, organizadores e presentes com personalização.',
        ]);
    }
}
