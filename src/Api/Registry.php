<?php

declare(strict_types=1);

namespace Polaris\Api;

use InvalidArgumentException;

/**
 * The registry of every API group a site exposes.
 *
 * This class is the single source of truth for "what can be called". Three
 * consumers read from it and none of them keeps its own list:
 *
 * - the MCP endpoint, for `tools/list` and `tools/call`;
 * - the REST router, which mounts one route per operation with a binding;
 * - the OpenAPI document, generated from the same schemas.
 *
 * Adding an operation to a group therefore creates the REST endpoint and the
 * MCP tool at the same time. That is the whole point: an API and its tools that
 * are written separately drift, and the drift is invisible until a client sends
 * an argument the tool does not accept any more.
 */
final class Registry
{
    /** @var array<string,ApiGroup> */
    private array $groups = [];

    /** @param array<int,ApiGroup> $groups */
    public function __construct(array $groups = [])
    {
        foreach ($groups as $group) {
            $this->add($group);
        }
    }

    public function add(ApiGroup $group): void
    {
        if (isset($this->groups[$group->name])) {
            throw new InvalidArgumentException('Duplicate API group: ' . $group->name);
        }

        $this->groups[$group->name] = $group;
    }

    /** @return array<string,ApiGroup> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return array<int,Operation> */
    public function operations(): array
    {
        $operations = [];

        foreach ($this->groups as $group) {
            foreach ($group->operations() as $operation) {
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    /**
     * Every operation name, qualified with its group: `users.search`.
     *
     * MCP tool names must be unique across the server, so an unqualified name
     * would collide the moment two groups both define `list`.
     *
     * @return array<string,Operation> qualified name => operation
     */
    public function qualified(): array
    {
        $qualified = [];

        foreach ($this->groups as $groupName => $group) {
            foreach ($group->operations() as $operation) {
                $qualified[$groupName . '.' . $operation->name] = $operation;
            }
        }

        return $qualified;
    }

    public function operation(string $qualifiedName): Operation
    {
        return $this->qualified()[$qualifiedName]
            ?? throw new InvalidArgumentException('Unknown operation: ' . $qualifiedName);
    }

    /**
     * The MCP tools/list payload.
     *
     * @return array<int,array<string,mixed>>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->groups as $groupName => $group) {
            foreach ($group->definitions() as $definition) {
                // Qualify the tool name the same way the caller will.
                $definition['name'] = $groupName . '.' . $definition['name'];
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * Call a qualified operation such as `users.search`.
     *
     * @param array<string,mixed> $arguments
     */
    public function call(string $qualifiedName, array $arguments): mixed
    {
        return $this->operation($qualifiedName)->call($arguments);
    }

    /**
     * The OpenAPI 3.1 document for the operations that have a REST binding.
     *
     * Generated rather than written: a hand-maintained spec is wrong the first
     * time somebody adds a field, and nobody notices until an integrator does.
     *
     * @param string $serverUrl Absolute URL of the site.
     * @param bool $protectReads Whether GET operations are key-protected too.
     *        True by default: `RestRouter::mount` applies the middleware to the
     *        whole group, so documenting a public GET would be a lie.
     * @return array<string,mixed>
     */
    public function openApi(
        string $serverUrl,
        string $title,
        string $version = '1.0.0',
        bool $protectReads = true,
    ): array {
        $paths = [];
        $schemas = [];

        foreach ($this->groups as $groupName => $group) {
            foreach ($group->restOperations() as $operation) {
                $key = $groupName . '.' . $operation->name;
                $operationId = str_replace('.', '_', $key);

                $operationSpec = [
                    'operationId' => $operationId,
                    'summary' => $operation->description,
                    'tags' => [$groupName],
                ];

                $parameters = [];
                $query = [];
                $body = null;

                foreach ($operation->properties as $name => $schema) {
                    // A path placeholder becomes a path parameter, everything
                    // else on a GET becomes a query parameter.
                    if (str_contains((string) $operation->path, '{' . $name . '}')) {
                        $parameters[] = [
                            'name' => $name,
                            'in' => 'path',
                            'required' => true,
                            'schema' => $schema,
                        ];

                        continue;
                    }

                    if ($operation->httpMethod === 'GET') {
                        $query[] = [
                            'name' => $name,
                            'in' => 'query',
                            'required' => in_array($name, $operation->required, true),
                            'schema' => $schema,
                        ];

                        continue;
                    }

                    $body = $body ?? ['type' => 'object', 'properties' => []];
                    $body['properties'][$name] = $schema;
                }

                if ($query !== []) {
                    $parameters = array_merge($parameters, $query);
                }

                if ($parameters !== []) {
                    $operationSpec['parameters'] = $parameters;
                }

                if ($body !== null) {
                    if ($operation->required !== []) {
                        $body['required'] = array_values($operation->required);
                    }

                    $operationSpec['requestBody'] = [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => $body]],
                    ];
                }

                $operationSpec['responses'] = [
                    '200' => ['description' => 'Successful response'],
                    '400' => ['description' => 'Invalid arguments'],
                    '401' => ['description' => 'Missing or invalid API key'],
                ];

                if ($operation->mutating || $protectReads) {
                    $operationSpec['security'] = [['apiKey' => []]];
                }

                $paths[$operation->path][strtolower((string) $operation->httpMethod)] = $operationSpec;
            }

            $schemas[$groupName] = ['type' => 'object'];
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => $title, 'version' => $version],
            'servers' => [['url' => rtrim($serverUrl, '/')]],
            'components' => [
                'securitySchemes' => [
                    'apiKey' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-KEY',
                    ],
                ],
                'schemas' => $schemas,
            ],
            'paths' => $paths,
        ];
    }
}
