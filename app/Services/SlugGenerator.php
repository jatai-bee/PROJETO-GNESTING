<?php

declare(strict_types=1);

namespace GNesting\Services;

final class SlugGenerator
{
    /**
     * Gera um slug único a partir do texto: "relogio", "relogio-2", "relogio-3"...
     *
     * @param callable(string): bool $exists
     */
    public static function unique(string $text, callable $exists, int $maxLength): string
    {
        $base = substr(slugify($text), 0, $maxLength - 4) ?: 'item';
        $base = rtrim($base, '-');
        $slug = $base;

        for ($i = 2; $exists($slug); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }
}
