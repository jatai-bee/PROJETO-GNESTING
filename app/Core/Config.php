<?php

declare(strict_types=1);

namespace GNesting\Core;

/**
 * Configuração carregada de /config/*.php. Acesso por "arquivo.chave.subchave".
 */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = [])
    {
    }

    public static function fromDirectory(string $directory): self
    {
        $items = [];
        foreach (glob(rtrim($directory, '/\\') . '/*.php') ?: [] as $file) {
            $items[basename($file, '.php')] = require $file;
        }

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->items;
        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
        $target = $value;
    }
}
