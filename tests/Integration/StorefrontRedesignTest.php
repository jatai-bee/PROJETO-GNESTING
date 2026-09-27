<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Enums\AdminRole;

/**
 * Etapa 14 (docs/19): favoritos, filtros rápidos do catálogo e o botão "Remover dados de demonstração".
 */
final class StorefrontRedesignTest extends HttpTestCase
{
    private function referenceProductId(): int
    {
        return (int) $this->fetchValue("SELECT product_id FROM product_variants WHERE sku = 'REL-GEO-001'");
    }

    public function testGuestFavoriteGoesToLoginAndComesBack(): void
    {
        $id = $this->referenceProductId();
        $response = $this->post('/favoritos/' . $id, ['voltar' => '/produtos']);

        self::assertSame(303, $response->status());
        self::assertStringContainsString('/entrar?voltar=%2Fprodutos', (string) $response->header('Location'));
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM wishlists WHERE product_id = :p', ['p' => $id]));
        self::assertSame(303, $this->get('/conta/favoritos')->status(), 'Lista de favoritos exige conta');
    }

    public function testCustomerTogglesFavoriteAndSeesItInTheList(): void
    {
        $this->registerCustomer('favoritos@cliente.test');
        $id = $this->referenceProductId();

        $this->post('/favoritos/' . $id, ['voltar' => '/produto/relogio-geometrico-g-nesting']);
        $list = $this->get('/conta/favoritos')->body();
        self::assertStringContainsString('Relógio Geométrico G-Nesting', $list);
        self::assertStringContainsString('aria-pressed="true"', $list, 'Coração marcado no cartão');
        self::assertMatchesRegularExpression('#header-action__count">1<#', $list, 'Contador no cabeçalho');

        $back = $this->post('/favoritos/' . $id, ['voltar' => 'https://outro-site.test/']);
        self::assertStringEndsWith('/produto/relogio-geometrico-g-nesting', (string) $back->header('Location'), 'Destino externo ignorado');
        self::assertStringContainsString('Nenhum favorito ainda', $this->get('/conta/favoritos')->body());
        self::assertSame(404, $this->post('/favoritos/999999')->status());
    }

    public function testQuickFiltersNarrowTheCatalogAndShowRemovableChips(): void
    {
        $id = $this->referenceProductId();
        $this->db->pdo()->exec("UPDATE product_variants SET compare_at_price_cents = price_cents + 3000 WHERE sku = 'REL-GEO-001'");

        $offers = $this->get('/produtos', ['oferta' => '1'])->body();
        self::assertStringContainsString('Relógio Geométrico G-Nesting', $offers);
        self::assertStringContainsString('Remover filtro: Em oferta', $offers);
        self::assertStringContainsString('noindex', $offers, 'Listagem filtrada não é indexada');

        $this->db->pdo()->exec("UPDATE product_variants SET compare_at_price_cents = NULL WHERE product_id = {$id}");
        self::assertStringContainsString('Nenhum produto com esses filtros', $this->get('/produtos', ['oferta' => '1'])->body());
        self::assertStringNotContainsString('Remover filtro', $this->get('/produtos', ['oferta' => 'x'])->body(), 'Valor inválido é ignorado');
    }

    public function testDemoRemovalNeedsOwnerAndTypedConfirmation(): void
    {
        $this->loginAdmin(AdminRole::Manager);
        self::assertSame(403, $this->post('/admin/sistema/demonstracao/remover', ['confirmacao' => 'REMOVER'])->status());

        $this->loginAdmin(AdminRole::Owner);
        $this->post('/admin/sistema/demonstracao/remover', ['confirmacao' => 'sim']);
        self::assertStringContainsString('digite REMOVER', $this->get('/admin/sistema')->body());

        $this->post('/admin/sistema/demonstracao/remover', ['confirmacao' => 'remover']);
        self::assertStringContainsString('Não há dados de demonstração', $this->get('/admin/sistema')->body());
    }
}
