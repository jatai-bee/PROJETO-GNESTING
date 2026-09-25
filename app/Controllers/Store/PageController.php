<?php

declare(strict_types=1);

namespace GNesting\Controllers\Store;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;

/**
 * Páginas institucionais. O texto fica em app/Views/store/pages/{template}.php.
 * A rota informa a página pelo último segmento da URL.
 */
final class PageController extends Controller
{
    /** URL → [template, título, descrição] */
    public const PAGES = [
        'sobre' => ['sobre', 'Sobre a G-Nesting', 'Conheça a G-Nesting: objetos de design produzidos com fabricação digital e acabamento cuidadoso.'],
        'como-fazemos' => ['como-fazemos', 'Como fazemos', 'Do arquivo digital à embalagem: como cada objeto G-Nesting é produzido.'],
        'trocas-e-devolucoes' => ['trocas', 'Trocas e devoluções', 'Política de trocas, devoluções e arrependimento da G-Nesting.'],
        'privacidade' => ['privacidade', 'Política de privacidade', 'Como a G-Nesting trata seus dados pessoais, conforme a LGPD.'],
        'termos' => ['termos', 'Termos de uso', 'Condições de uso da loja G-Nesting.'],
    ];

    public function show(Request $request): Response
    {
        $slug = ltrim($request->path(), '/');
        $page = self::PAGES[$slug] ?? throw HttpException::notFound();
        [$template, $heading, $description] = $page;

        return $this->render('store/pages/' . $template, [
            'title' => $heading . ' | G-Nesting',
            'metaDescription' => $description,
            'canonical' => absolute_url('/' . $slug),
            'heading' => $heading,
            'breadcrumbs' => [['label' => $heading, 'url' => null]],
        ]);
    }
}
