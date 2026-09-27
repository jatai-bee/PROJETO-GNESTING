<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GNesting\Enums\AdminRole;
use GNesting\Services\DashboardService;

/**
 * Etapa 14, fase 2 (docs/19): visão geral por período e por papel, menu agrupado e trilha.
 */
final class AdminDashboardTest extends HttpTestCase
{
    public function testManagerSeesSalesOfThePeriodAndEveryRoleSeesOnlyWhatItUses(): void
    {
        $this->loginAdmin(AdminRole::Manager);
        $page = $this->get('/admin', ['periodo' => '7d'])->body();
        foreach (['Faturamento', 'Ticket médio', 'Vendas por dia', 'Pedidos por etapa', 'Precisa de atenção', 'Últimos pedidos'] as $text) {
            self::assertStringContainsString($text, $page, $text);
        }
        self::assertMatchesRegularExpression('#aria-current="page">Últimos 7 dias<#', $page);
        self::assertMatchesRegularExpression('#aria-current="page">Últimos 30 dias<#', $this->get('/admin', ['periodo' => 'x'])->body(), 'Período inválido volta ao padrão');
        self::assertStringNotContainsString('Atividade da equipe', $page, 'Auditoria só para o proprietário');

        $this->loginAdmin(AdminRole::Production);
        $page = $this->get('/admin')->body();
        self::assertStringNotContainsString('Faturamento', $page);
        self::assertStringContainsString('Pedidos por etapa', $page);
        self::assertStringContainsString('Precisa de atenção', $page, 'Produção vê matéria-prima em falta');
        self::assertStringNotContainsString('href="/admin/produtos"', $page, 'Menu sem módulos proibidos');

        $this->loginAdmin(AdminRole::Support);
        $page = $this->get('/admin')->body();
        self::assertStringNotContainsString('Precisa de atenção', $page);
        self::assertStringContainsString('Últimos pedidos', $page);

        $this->loginAdmin(AdminRole::Owner);
        self::assertStringContainsString('Atividade da equipe', $this->get('/admin')->body());
    }

    public function testMenuIsGroupedAndTheTrailFollowsThePage(): void
    {
        $this->loginAdmin(AdminRole::Owner);
        $page = $this->get('/admin/materiais')->body();
        self::assertStringContainsString('admin-menu__label">Produção<', $page);
        self::assertMatchesRegularExpression('#<span aria-current="page">Matérias-primas</span>#', $page, 'Trilha termina no módulo');
        self::assertStringContainsString('<a href="/admin">Painel</a>', $page);
    }

    public function testPeriodBoundariesAndComparison(): void
    {
        $service = $this->container->get(DashboardService::class);
        $now = new DateTimeImmutable('2026-03-15 10:00:00', new DateTimeZone('America/Sao_Paulo'));

        $week = $service->sales('7d', $now);
        self::assertCount(7, $week['series']);
        self::assertSame(['2026-03-09', '2026-03-15'], [$week['series'][0]['date'], $week['series'][6]['date']]);

        $month = $service->sales('mes', $now);
        self::assertSame('01/03', $month['series'][0]['label']);
        self::assertCount(15, $month['series']);

        self::assertSame(50, DashboardService::change(150, 100));
        self::assertSame(-25, DashboardService::change(75, 100));
        self::assertNull(DashboardService::change(10, 0), 'Sem base anterior não inventa porcentagem');
    }

    public function testPaidOrderCountsInTheDayItWasPaid(): void
    {
        $service = $this->container->get(DashboardService::class);
        $before = $service->sales('7d')['totals'];

        // Pedido pago agora, sem depender do fluxo de checkout
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO customers (name, email) VALUES ('Cliente Painel', 'painel@cliente.test')");
        $customerId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO orders (number, access_token_hash, customer_id, status, payment_status, subtotal_cents, discount_cents, shipping_cents, total_cents,
                        customer_name, customer_email, ship_recipient, ship_zip_code, ship_street, ship_number, ship_district, ship_city, ship_state,
                        shipping_carrier, shipping_service, shipping_days, production_days, placed_at, paid_at)
                    VALUES ('GN-TESTE-000001', REPEAT('a', 64), {$customerId}, 'production_pending', 'paid', 10000, 0, 2500, 12500,
                        'Cliente Painel', 'painel@cliente.test', 'Cliente Painel', '01310100', 'Av. Paulista', '1000', 'Bela Vista', 'São Paulo', 'SP',
                        'Correios', 'PAC', 8, 3, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $after = $service->sales('7d');

        self::assertSame($before['revenue'] + 12500, $after['totals']['revenue']);
        self::assertSame($before['orders'] + 1, $after['totals']['orders']);
        $today = $after['series'][array_key_last($after['series'])];
        self::assertGreaterThanOrEqual(12500, $today['revenue'], 'Entra no dia de hoje');
    }
}
