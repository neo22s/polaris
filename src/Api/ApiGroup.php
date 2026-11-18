<?php

declare(strict_types=1);

namespace Polaris\Api;

use InvalidArgumentException;

/**
 * A collection of operations with one name.
 *
 * A group is the seam that keeps the surfaces in sync: it knows its operations,
 * and it knows how to run one. It does not know REST, JSON-RPC or JSON Schema
 * beyond what `Operation` already carries, which is what lets the same group be
 * mounted as an MCP tool list and as REST routes.
 *
 * Operations are keyed by name, and the name is also what a client calls, so a
 * duplicate fails at construction instead of silently shadowing one.
 */
final class ApiGroup
{
    /** @var array<string,Operation> */
    private array $operations = [];

    /** @param array<int,Operation> $operations */
    public function __construct(public readonly string $name, array $operations = [])
    {
        foreach ($operations as $operation) {
            $this->add($operation);
        }
    }

    public function add(Operation $operation): void
    {
        if (isset($this->operations[$operation->name])) {
            throw new InvalidArgumentException(sprintf(
                'Duplicate operation "%s" in group "%s".',
                $operation->name,
                $this->name,
            ));
        }

        $this->operations[$operation->name] = $operation;
    }

    /** @return array<int,Operation> */
    public function operations(): array
    {
        return array_values($this->operations);
    }

    public function has(string $name): bool
    {
        return isset($this->operations[$name]);
    }

    public function get(string $name): Operation
    {
        return $this->operations[$name]
            ?? throw new InvalidArgumentException(sprintf('Unknown operation "%s" in group "%s".', $name, $this->name));
    }

    /**
     * Run an operation by name.
     *
     * @param array<string,mixed> $arguments
     */
    public function call(string $name, array $arguments): mixed
    {
        return $this->get($name)->call($arguments);
    }

    /**
     * The MCP catalogue for this group.
     *
     * @return array<int,array<string,mixed>>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->operations() as $operation) {
            $definitions[] = $operation->definition();
        }

        return $definitions;
    }

    /** @return array<int,Operation> Only the operations with a REST binding. */
    public function restOperations(): array
    {
        return array_values(array_filter(
            $this->operations(),
            static fn (Operation $operation): bool => $operation->hasRestBinding(),
        ));
    }
}
