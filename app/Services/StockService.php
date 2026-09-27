<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\AuditContext;
use GNesting\Core\Database;
use GNesting\Repositories\InventoryRepository;
use GNesting\Repositories\StockRepository;

/**
 * Acerto de estoque de produto acabado pela tela Estoque: contagem (quantidade física) e mínimo
 * para o alerta. A diferença vira movimentação "adjust" e fica na auditoria.
 */
final class StockService
{
    public function __construct(
        private readonly Database $db,
        private readonly StockRepository $stock,
        private readonly InventoryRepository $inventory,
        private readonly AuditService $audit,
        private readonly AuditContext $auditContext,
    ) {
    }

    /** @throws BusinessRuleException */
    public function adjust(int $variantId, int $quantity, ?int $reorderLevel, string $reason): void
    {
        $reason = trim($reason);
        if ($quantity < 0 || $quantity > 1000000 || ($reorderLevel !== null && ($reorderLevel < 0 || $reorderLevel > 1000000))) {
            throw new BusinessRuleException('Informe quantidades entre 0 e 1.000.000.');
        }

        $this->db->transaction(function () use ($variantId, $quantity, $reorderLevel, $reason): void {
            $current = $this->stock->lockInventory($variantId) ?? throw new BusinessRuleException('Variação não encontrada.');
            if ($current['stock_mode'] !== 'stock') {
                throw new BusinessRuleException('Este produto é feito sob encomenda: a quantidade não é controlada. Mude para "Pronta entrega" no cadastro do produto.');
            }
            if ($quantity < (int) $current['quantity_reserved']) {
                throw new BusinessRuleException("Há {$current['quantity_reserved']} unidade(s) reservada(s) em pedidos: a quantidade não pode ficar abaixo disso.");
            }
            $delta = $quantity - (int) $current['quantity_on_hand'];
            if ($delta !== 0 && $reason === '') {
                throw new BusinessRuleException('Informe o motivo do acerto (ex.: "Contagem de sexta" ou "Lote produzido").');
            }

            $this->stock->setQuantityAndMinimum($variantId, $quantity, $reorderLevel);
            if ($delta !== 0) {
                $this->inventory->addMovement($variantId, 'adjust', $delta, mb_substr($reason, 0, 200), $this->auditContext->userId());
            }
            $this->audit->record(AuditService::STOCK_CHANGE, 'product_variant', $variantId,
                ['quantity_on_hand' => (int) $current['quantity_on_hand'], 'reorder_level' => $current['reorder_level']],
                ['quantity_on_hand' => $quantity, 'reorder_level' => $reorderLevel, 'reason' => $reason]);
        });
    }
}
