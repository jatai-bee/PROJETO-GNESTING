<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GNesting\Enums\AdminRole;

/**
 * Etapa 14, fase 5 (docs/19): regras de acessibilidade verificadas no HTML de cada tela principal.
 * Idioma, um h1, títulos sem pular nível, imagem com alt, campo com rótulo, botão e link com nome,
 * id único e nenhum style="" (a CSP bloquearia).
 */
final class AccessibilityTest extends HttpTestCase
{
    /** @return list<string> problemas encontrados */
    private function audit(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        $problems = [];

        if (!str_contains($html, '<html lang="pt-BR">')) {
            $problems[] = 'sem lang="pt-BR"';
        }
        if ($xp->query('//main')->length !== 1) {
            $problems[] = 'precisa de um <main>';
        }
        if (($h1 = $xp->query('//h1')->length) !== 1) {
            $problems[] = "{$h1} h1";
        }
        $last = 0;
        foreach ($xp->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
            $level = (int) substr($heading->nodeName, 1);
            if ($last > 0 && $level > $last + 1) {
                $problems[] = "título pula de h{$last} para h{$level}: " . trim($heading->textContent);
            }
            $last = $level;
        }
        foreach ($xp->query('//img[not(@alt)]') as $img) {
            $problems[] = 'imagem sem alt: ' . $img->getAttribute('src');
        }
        $ids = [];
        foreach ($xp->query('//*[@id]') as $el) {
            $ids[] = $el->getAttribute('id');
        }
        foreach (array_keys(array_filter(array_count_values($ids), static fn (int $n): bool => $n > 1)) as $id) {
            $problems[] = "id repetido: {$id}";
        }
        foreach ($xp->query('//input[not(@type="hidden")]|//select|//textarea') as $field) {
            \assert($field instanceof DOMElement);
            $id = $field->getAttribute('id');
            $labelled = $field->getAttribute('aria-label') !== '' || $field->hasAttribute('aria-labelledby')
                || ($id !== '' && $xp->query("//label[@for='{$id}']")->length > 0)
                || $xp->query('ancestor::label', $field)->length > 0;
            if (!$labelled) {
                $problems[] = 'campo sem rótulo: ' . $field->getAttribute('name');
            }
        }
        foreach ($xp->query('//button|//a[@href]') as $el) {
            \assert($el instanceof DOMElement);
            if ($el->getAttribute('aria-hidden') === 'true') {
                continue;
            }
            $name = trim($el->textContent . $el->getAttribute('aria-label') . $el->getAttribute('title'));
            foreach ($xp->query('.//img', $el) as $img) {
                \assert($img instanceof DOMElement);
                $name .= $img->getAttribute('alt');
            }
            if ($name === '') {
                $problems[] = $el->nodeName . ' sem nome acessível';
            }
        }
        if ($xp->query('//*[@style]')->length > 0) {
            $problems[] = 'style="" no HTML (bloqueado pela CSP)';
        }

        return $problems;
    }

    public function testStorePagesFollowTheRules(): void
    {
        foreach (['/', '/produtos', '/produtos?oferta=1', '/produto/relogio-geometrico-g-nesting', '/busca?q=zzz', '/carrinho',
            '/entrar', '/cadastro', '/sobre', '/como-fazemos', '/nao-existe'] as $path) {
            [$uri, $query] = array_pad(explode('?', $path, 2), 2, '');
            parse_str($query, $params);
            self::assertSame([], $this->audit($this->get($uri, $params)->body()), $path);
        }

        $this->registerCustomer('acessivel@cliente.test');
        foreach (['/conta', '/conta/pedidos', '/conta/favoritos'] as $path) {
            self::assertSame([], $this->audit($this->get($path)->body()), $path);
        }
    }

    public function testAdminPagesFollowTheRules(): void
    {
        $this->loginAdmin(AdminRole::Owner);
        $productId = (int) $this->fetchValue("SELECT product_id FROM product_variants WHERE sku = 'REL-GEO-001'");
        foreach (['/admin', '/admin/pedidos', '/admin/clientes', '/admin/produtos', "/admin/produtos/{$productId}/editar", '/admin/produtos/novo',
            "/admin/produtos/{$productId}/variantes", '/admin/categorias', '/admin/estoque', '/admin/relatorios', '/admin/producao',
            '/admin/expedicao', '/admin/materiais', '/admin/cupons', '/admin/usuarios', '/admin/sistema'] as $path) {
            self::assertSame([], $this->audit($this->get($path)->body()), $path);
        }
    }
}
