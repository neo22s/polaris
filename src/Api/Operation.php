<?php

declare(strict_types=1);

namespace Polaris\Api;

use InvalidArgumentException;

/**
 * One operation, described once.
 *
 * This is the unit that both surfaces are built from: a REST route and an MCP
 * tool are the same operation with a different transport. Describing it twice
 * is how an API and its tools drift apart — the tool keeps working with old
 * rules long after the endpoint changed.
 *
 * An operation is immutable: name, JSON Schema, handler and (optionally) an
 * HTTP binding. The schema is JSON Schema because MCP requires exactly that
 * shape, and because OpenAPI can be generated from it without translation.
 */
final class Operation
{
    /**
     * @param array<string,mixed> $properties JSON Schema properties.
     * @param array<int,string> $required Required property names.
     * @param \Closure(array<string,mixed>):mixed $handler
     */
    private function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $properties,
        public readonly array $required,
        private readonly \Closure $handler,
        public readonly ?string $httpMethod = null,
        public readonly ?string $path = null,
        public readonly bool $mutating = false,
    ) {
    }

    /**
     * An operation with no REST binding (tools only).
     *
     * @param array<string,mixed> $properties
     * @param array<int,string> $required
     * @param \Closure(array<string,mixed>):mixed $handler
     */
    public static function tool(
        string $name,
        string $description,
        array $properties = [],
        array $required = [],
        ?\Closure $handler = null,
    ): self {
        return new self(
            $name,
            $description,
            $properties,
            $required,
            $handler ?? throw new InvalidArgumentException('An operation needs a handler: ' . $name),
        );
    }

    /**
     * An operation exposed as a REST route *and* as an MCP tool.
     *
     * @param array<string,mixed> $properties
     * @param array<int,string> $required
     * @param \Closure(array<string,mixed>):mixed $handler
     */
    public static function both(
        string $name,
        string $description,
        string $httpMethod,
        string $path,
        array $properties = [],
        array $required = [],
        ?\Closure $handler = null,
    ): self {
        $method = strtoupper($httpMethod);

        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException('Unsupported HTTP method: ' . $httpMethod);
        }

        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException('A REST path must start with "/": ' . $path);
        }

        return new self(
            $name,
            $description,
            $properties,
            $required,
            $handler ?? throw new InvalidArgumentException('An operation needs a handler: ' . $name),
            $method,
            $path,
            in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true),
        );
    }

    public function hasRestBinding(): bool
    {
        return $this->httpMethod !== null && $this->path !== null;
    }

    /**
     * The MCP tool definition. `properties` is always a JSON object, even when
     * empty: an empty array would serialise as `[]` and OpenAI-style clients
     * reject it.
     *
     * @return array<string,mixed>
     */
    public function definition(): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $this->properties === [] ? new \stdClass() : $this->properties,
        ];

        if ($this->required !== []) {
            $schema['required'] = array_values($this->required);
        }

        return [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $schema,
        ];
    }

    /**
     * Call the handler after checking the arguments against the schema.
     *
     * Only the two checks that prevent silent bugs are done here: a missing
     * required argument, and an argument nobody declared. Types are the
     * handler's business, because a JSON schema type and a PHP value rarely
     * match one-to-one (`integer` for an id is fine; a `number` arriving as a
     * string is a caller bug the handler wants to report itself).
     *
     * @param array<string,mixed> $arguments
     */
    public function call(array $arguments): mixed
    {
        foreach ($this->required as $field) {
            if (!array_key_exists($field, $arguments)) {
                throw new InvalidArgumentException(sprintf('Missing required argument: %s', $field));
            }
        }

        // Even with no declared properties the schema is an object, so any
        // argument at all is undeclared. Guarding this with `properties !== []`
        // let a client pass anything to a no-argument tool, which is exactly
        // the silent drift this check exists to prevent.
        $unknown = array_diff(array_keys($arguments), array_keys($this->properties));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown argument(s) for %s: %s',
                $this->name,
                implode(', ', $unknown),
            ));
        }

        return ($this->handler)($arguments);
    }
}
