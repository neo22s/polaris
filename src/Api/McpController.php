<?php

declare(strict_types=1);

namespace Polaris\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * The MCP transport (JSON-RPC 2.0).
 *
 * A transport, not a catalogue: the tools come from the same Registry the REST
 * routes come from, so an operation added to a group appears here without being
 * written twice. The protocol handling (parse error, invalid request, method not
 * found) follows the JSON-RPC spec literally, because a client library relies on
 * those exact codes.
 */
final class McpController
{
    private const PROTOCOL_VERSION = '2024-11-05';

    /** @param array<string,mixed> $serverInfo */
    public function __construct(
        private Registry $registry,
        private array $serverInfo = [],
    ) {
    }

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $raw = (string) $request->getBody();

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->respond($response, null, ['code' => -32700, 'message' => 'Parse error'], null);
        }

        if (!is_array($decoded)) {
            return $this->respond($response, null, ['code' => -32700, 'message' => 'Parse error'], null);
        }

        $id = array_key_exists('id', $decoded) ? $decoded['id'] : null;

        if (($decoded['jsonrpc'] ?? null) !== '2.0') {
            return $this->respond($response, $id, ['code' => -32600, 'message' => 'Invalid Request'], null);
        }

        $method = $decoded['method'] ?? null;

        if (!is_string($method)) {
            return $this->respond($response, $id, ['code' => -32600, 'message' => 'method is required'], null);
        }

        if (!in_array($method, ['initialize', 'ping', 'tools/list', 'tools/call'], true)) {
            return $this->respond($response, $id, ['code' => -32601, 'message' => 'Method not found'], null);
        }

        $params = isset($decoded['params']) && is_array($decoded['params']) ? $decoded['params'] : [];

        try {
            return $this->respond($response, $id, null, $this->dispatch($method, $params));
        } catch (\InvalidArgumentException $e) {
            return $this->respond($response, $id, ['code' => -32602, 'message' => $e->getMessage()], null);
        } catch (\Throwable $e) {
            error_log('[Polaris] MCP call failed: ' . $e->getMessage());

            return $this->respond($response, $id, ['code' => -32603, 'message' => 'Internal error'], null);
        }
    }

    /** @param array<string,mixed> $params */
    private function dispatch(string $method, array $params): mixed
    {
        return match ($method) {
            'initialize' => $this->initialize(),
            'ping' => new \stdClass(),
            'tools/list' => ['tools' => $this->registry->definitions()],
            'tools/call' => $this->callTool($params),
        };
    }

    /** @return array<string,mixed> */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => $this->serverInfo + ['name' => 'polaris', 'version' => '1.0.0'],
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');

        if ($name === '') {
            throw new \InvalidArgumentException('Tool name is required');
        }

        $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
        $result = $this->registry->call($name, $arguments);
        $text = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'content' => [['type' => 'text', 'text' => $text !== false ? $text : '{}']],
            'isError' => false,
        ];
    }

    private function respond(ResponseInterface $response, mixed $id, ?array $error, mixed $result): ResponseInterface
    {
        $payload = ['jsonrpc' => '2.0', 'id' => $id];

        if ($error !== null) {
            $payload['error'] = $error;
        } else {
            $payload['result'] = $result;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json = '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}';
        }

        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write($json);

        return $response;
    }
}
