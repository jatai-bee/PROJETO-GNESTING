<?php

declare(strict_types=1);

namespace GNesting\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Container de dependências mínimo.
 * - set(): registra uma fábrica (resolvida uma vez; instância compartilhada)
 * - instance(): registra um objeto pronto
 * - get(): devolve a instância; classes não registradas são construídas por
 *   autowiring (parâmetros tipados com classes são resolvidos recursivamente).
 */
final class Container
{
    /** @var array<string, Closure> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->resolving[$id])) {
            throw new RuntimeException("Dependência circular ao resolver {$id}.");
        }
        $this->resolving[$id] = true;

        try {
            $object = isset($this->factories[$id])
                ? ($this->factories[$id])($this)
                : $this->build($id);
        } finally {
            unset($this->resolving[$id]);
        }

        return $this->instances[$id] = $object;
    }

    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Serviço não encontrado: {$class}.");
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Classe não instanciável: {$class}.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                /** @var class-string $dependency */
                $dependency = $type->getName();
                $arguments[] = $this->get($dependency);
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }
            throw new RuntimeException(
                "Não é possível resolver o parâmetro \${$parameter->getName()} de {$class}; registre uma fábrica."
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
