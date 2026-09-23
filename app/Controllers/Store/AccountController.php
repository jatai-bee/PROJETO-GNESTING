<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;

final class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render('store/account/index', [
            'title' => 'Minha conta | G-Nesting',
            'customer' => $request->attribute('customer'),
        ]);
    }
}
