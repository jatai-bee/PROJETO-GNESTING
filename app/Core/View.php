<?php

declare(strict_types=1);

namespace GNesting\Core;

use InvalidArgumentException;
use Throwable;

/**
 * Renderizador de templates PHP nativos em app/Views.
 *
 * Regras para templates:
 * - toda variável impressa passa por e()
 * - nenhum acesso a banco ou sessão diretamente; os dados chegam prontos
 * - $this->partial('partials/nome', [...]) para trechos reutilizáveis
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $viewsPath)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $vars = $data + $this->shared;
        $content = $this->renderFile($template, $vars);

        if ($layout === null) {
            return $content;
        }

        return $this->renderFile('layouts/' . $layout, ['content' => $content] + $vars);
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->renderFile($template, $data + $this->shared);
    }

    /** @param array<string, mixed> $__vars */
    private function renderFile(string $__template, array $__vars): string
    {
        if (!preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-]+)*$#', $__template)) {
            throw new InvalidArgumentException("Nome de template inválido: {$__template}");
        }
        $__file = $this->viewsPath . '/' . $__template . '.php';
        if (!is_file($__file)) {
            throw new InvalidArgumentException("Template não encontrado: {$__template}");
        }

        extract($__vars, EXTR_SKIP);
        $__level = ob_get_level();
        ob_start();
        try {
            include $__file;

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $__level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
