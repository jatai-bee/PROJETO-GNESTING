<?php

declare(strict_types=1);

namespace GNesting\Services\Production;

use GNesting\Enums\OrderStatus;
use GNesting\Enums\ProductionStage;

/**
 * Regras puras do fluxo de um job de produção (sem banco):
 * - rota = etapas da ficha + controle de qualidade e embalagem (sempre), na ordem canônica;
 * - job: queued → etapas da rota → done;
 * - status do pedido = etapa do job MAIS ATRASADO (docs/03 §6).
 */
final class ProductionFlow
{
    public const QUEUED = 'queued';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';

    /** Ordem canônica das etapas (a da tabela production_spec_steps). */
    public const CANONICAL = ['cnc', 'sanding', 'painting', 'drying', 'assembly', 'quality', 'packaging'];

    /** Etapas obrigatórias para qualquer peça. */
    private const REQUIRED = ['quality', 'packaging'];

    /**
     * @param list<string> $specStages etapas da ficha (qualquer ordem, podem repetir)
     * @return list<string>
     */
    public static function route(array $specStages): array
    {
        $stages = array_unique([...$specStages, ...self::REQUIRED]);

        return array_values(array_filter(self::CANONICAL, static fn (string $s): bool => in_array($s, $stages, true)));
    }

    /** @param list<string> $route */
    public static function next(array $route, string $stage): string
    {
        if ($stage === self::QUEUED) {
            return $route[0] ?? self::DONE;
        }
        $index = array_search($stage, $route, true);

        return $index === false || !isset($route[$index + 1]) ? self::DONE : $route[$index + 1];
    }

    /**
     * Etapas para onde o retrabalho pode voltar (antes do controle de qualidade).
     *
     * @param list<string> $route
     * @return list<string>
     */
    public static function reworkTargets(array $route): array
    {
        $quality = array_search('quality', $route, true);

        return $quality === false ? [] : array_slice($route, 0, $quality);
    }

    /** Posição para comparar jobs: fila < etapas < pronto. */
    public static function rank(string $stage): int
    {
        return match ($stage) {
            self::QUEUED => 0,
            self::DONE => 100,
            default => 1 + (int) array_search($stage, self::CANONICAL, true),
        };
    }

    public static function orderStatusFor(string $stage): OrderStatus
    {
        return match ($stage) {
            self::QUEUED => OrderStatus::ProductionPending,
            'cnc' => OrderStatus::InProduction,
            'sanding', 'painting', 'drying', 'assembly' => OrderStatus::Finishing,
            'quality' => OrderStatus::QualityControl,
            'packaging' => OrderStatus::Packaging,
            default => OrderStatus::ReadyToShip,
        };
    }

    /**
     * Status do pedido a partir das etapas dos seus jobs (o mais atrasado manda).
     *
     * @param list<string> $stages
     */
    public static function orderStatus(array $stages): OrderStatus
    {
        $slowest = self::DONE;
        foreach ($stages as $stage) {
            if ($stage !== self::CANCELLED && self::rank($stage) < self::rank($slowest)) {
                $slowest = $stage;
            }
        }

        return self::orderStatusFor($slowest);
    }

    /** Texto do botão de avançar: "Iniciar CNC", "Aprovar → Embalagem", "Concluir → Lixamento". */
    public static function actionLabel(string $stage, string $next): string
    {
        return match ($stage) {
            self::QUEUED => 'Iniciar ' . self::label($next),
            'quality' => 'Aprovar → ' . self::label($next),
            default => 'Concluir → ' . self::label($next),
        };
    }

    public static function label(string $stage): string
    {
        return match ($stage) {
            self::QUEUED => 'Na fila',
            self::DONE => 'Pronto',
            self::CANCELLED => 'Cancelado',
            default => ProductionStage::tryFrom($stage)?->label() ?? $stage,
        };
    }
}
