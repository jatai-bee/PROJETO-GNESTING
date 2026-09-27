<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GNesting\Enums\AdminRole;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\ReportService;
use GNesting\Services\StockService;

/**
 * Etapa 14, fase 4 (docs/19): estoque, quadro e ordem impressa da produção, pedidos em cartões,
 * expedição e relatórios com CSV.
 */
final class AdminOperationsTest extends HttpTestCase
{
    private function variantId(): int
    {
        return (int) $this->fetchValue("SELECT id FROM product_variants WHERE sku = 'REL-GEO-001'");
    }

    private function makeReadyStock(int $variantId, int $onHand, int $reserved = 0): void
    {
        $this->db->pdo()->exec("UPDATE inventory SET stock_mode = 'stock', quantity_on_hand = {$onHand}, quantity_reserved = {$reserved}, reorder_level = NULL WHERE variant_id = {$variantId}");
    }

    public function testStockAdjustmentRecordsMovementAndRespectsReservations(): void
    {
        $variantId = $this->variantId();
        $this->makeReadyStock($variantId, 10, 3);
        $service = $this->container->get(StockService::class);

        $service->adjust($variantId, 7, 4, 'Contagem de sexta');
        self::assertSame(['7', '4'], array_map('strval', array_values($this->db->pdo()->query("SELECT quantity_on_hand, reorder_level FROM inventory WHERE variant_id = {$variantId}")->fetch(\PDO::FETCH_ASSOC))));
        self::assertSame(-3, (int) $this->fetchValue("SELECT quantity FROM inventory_movements WHERE variant_id = :v AND type = 'adjust' ORDER BY id DESC LIMIT 1", ['v' => $variantId]));
        self::assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'stock_change' AND entity_type = 'product_variant' AND entity_id = :v", ['v' => $variantId]));

        foreach ([[2, 'abaixo do reservado'], [7, null]] as [$quantity, $case]) {
            try {
                $service->adjust($variantId, $quantity, 4, $case === null ? 'só o mínimo' : '');
                self::assertNull($case, 'Deveria recusar: ' . $case);
            } catch (BusinessRuleException $e) {
                self::assertStringContainsString('reservada', $e->getMessage());
            }
        }

        $this->db->pdo()->exec("UPDATE inventory SET stock_mode = 'made_to_order' WHERE variant_id = {$variantId}");
        $this->expectException(BusinessRuleException::class);
        $service->adjust($variantId, 5, null, 'x');
    }

    public function testStockPageListsShortagesFirstAndIsClosedToSupport(): void
    {
        $variantId = $this->variantId();
        $this->makeReadyStock($variantId, 0);

        $this->loginAdmin(AdminRole::Production);
        $page = $this->get('/admin/estoque', ['filtro' => 'alerta'])->body();
        self::assertStringContainsString('REL-GEO-001', $page);
        self::assertStringContainsString('Sem estoque', $page);
        self::assertStringContainsString('Matéria-prima', $this->get('/admin/estoque', ['aba' => 'materia-prima'])->body());

        $this->post("/admin/estoque/{$variantId}/ajuste", ['quantidade' => '12', 'minimo' => '3', 'motivo' => 'Lote produzido', 'voltar' => '/admin/estoque']);
        self::assertSame(12, (int) $this->fetchValue("SELECT quantity_on_hand FROM inventory WHERE variant_id = :v", ['v' => $variantId]));
        $this->post("/admin/estoque/{$variantId}/ajuste", ['quantidade' => '-1', 'voltar' => '/admin/estoque']);
        self::assertStringContainsString('números inteiros', $this->get('/admin/estoque')->body());

        $this->loginAdmin(AdminRole::Support);
        self::assertSame(403, $this->get('/admin/estoque')->status());
        self::assertSame(403, $this->post("/admin/estoque/{$variantId}/ajuste", ['quantidade' => '1'])->status());
    }

    public function testProductionBoardPrintableOrderAndOrderCards(): void
    {
        $this->loginAdmin(AdminRole::Owner);
        $board = $this->get('/admin/producao', ['visao' => 'quadro'])->body();
        self::assertStringContainsString('class="board"', $board);
        self::assertStringContainsString('Quadro por etapa', $board);

        $jobId = (int) $this->fetchValue('SELECT id FROM production_jobs ORDER BY id LIMIT 1');
        if ($jobId > 0) {
            $print = $this->get("/admin/producao/{$jobId}/imprimir");
            self::assertSame(200, $print->status());
            self::assertStringContainsString('Ordem de produção', $print->body());
            self::assertStringNotContainsString('admin-sidebar', $print->body(), 'Impressão sem o menu do painel');
        }

        self::assertStringContainsString('aria-current="page">Cartões<', $this->get('/admin/pedidos', ['visao' => 'cartoes'])->body());
        self::assertStringContainsString('Últimos entregues', $this->get('/admin/expedicao')->body());
    }

    public function testReportsByPeriodWithSafeCsvAndManagerOnly(): void
    {
        $this->loginAdmin(AdminRole::Manager);
        foreach (array_keys(ReportService::TABS) as $tab) {
            self::assertSame(200, $this->get('/admin/relatorios', ['aba' => $tab, 'periodo' => '90d'])->status(), $tab);
        }

        $csv = $this->get('/admin/relatorios', ['aba' => 'vendas', 'de' => '2026-01-01', 'ate' => '2026-01-03', 'formato' => 'csv']);
        self::assertStringStartsWith('text/csv', (string) $csv->header('Content-Type'));
        self::assertStringContainsString('gnesting-vendas-2026-01-01-a-2026-01-03.csv', (string) $csv->header('Content-Disposition'));
        self::assertStringStartsWith("\u{FEFF}\"Data\";\"Pedidos\"", $csv->body());
        self::assertSame(4, substr_count($csv->body(), "\r\n"), 'Cabeçalho + 3 dias');

        // Nome de cliente que começa com "=" não vira fórmula na planilha
        $this->db->pdo()->exec("UPDATE orders SET customer_name = '=HYPERLINK(\"x\")' WHERE paid_at IS NOT NULL");
        $clients = $this->get('/admin/relatorios', ['aba' => 'clientes', 'periodo' => 'ano', 'formato' => 'csv'])->body();
        self::assertStringNotContainsString(';"=HYPERLINK', $clients);

        foreach ([AdminRole::Production, AdminRole::Support] as $role) {
            $this->loginAdmin($role);
            self::assertSame(403, $this->get('/admin/relatorios')->status(), $role->value);
        }
    }

    public function testReportPeriodParsing(): void
    {
        $service = $this->container->get(ReportService::class);
        $now = new DateTimeImmutable('2026-03-15 12:00', new DateTimeZone('America/Sao_Paulo'));

        $last = $service->period('mes-anterior', '', '', $now);
        self::assertSame(['2026-02-01', '2026-02-28', 28], [$last['start']->format('Y-m-d'), $last['end']->format('Y-m-d'), $last['days']]);

        $swapped = $service->period('', '2026-03-10', '2026-03-01', $now);
        self::assertSame(['2026-03-01', '2026-03-10', null], [$swapped['start']->format('Y-m-d'), $swapped['end']->format('Y-m-d'), $swapped['preset']]);

        self::assertSame('30d', $service->period('x', '2026-02-31', '', $now)['preset'], 'Data inválida cai no atalho padrão');
        self::assertSame(1101, $service->period('', '2020-01-01', '2026-03-15', $now)['days'], 'Período longo é limitado');
    }
}
