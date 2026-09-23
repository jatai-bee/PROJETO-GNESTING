<?php

declare(strict_types=1);

namespace GNesting\Core;

use RuntimeException;

/**
 * Ponto de acesso estático ao container, usado apenas pelos helpers globais
 * (views e funções utilitárias). Classes devem receber dependências pelo construtor.
 */
final class App
{
    private static ?Container $container = null;

    public static function setContainer(Container $container): void
    {
        self::$container = $container;
    }

    public static function container(): Container
    {
        return self::$container ?? throw new RuntimeException('Aplicação não inicializada.');
    }
}
