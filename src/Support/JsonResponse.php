<?php

declare(strict_types=1);

namespace Polaris\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * The three JSON envelopes the API emits, written in one place.
 *
 * Each transport keeps its own shape (REST error, JSON-RPC result, JSON-RPC
 * error) but the encoding, headers and fallback all behave the same. Four
 * hand-written `json_encode` calls used to drift on flags and error fields.
 */
final class JsonResponse
{
    /**
     * @param array<string,mixed> $payload
     */
    public static function write(
        ResponseInterface $response,
        array $payload,
        int $status = 200,
        string $fallback = '{"error":{"status":500,"message":"Serialization failed"}}',
    ): ResponseInterface {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json = $fallback;
            $status = 500;
        }

        $response->getBody()->write($json);

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    public static function error(ResponseInterface $response, int $status, string $message): ResponseInterface
    {
        return self::write($response, ['error' => ['status' => $status, 'message' => $message]], $status);
    }

    /**
     * @param array<string,mixed>|null $error
     */
    public static function rpc(
        ResponseInterface $response,
        mixed $id,
        ?array $error,
        mixed $result,
        int $status = 200,
    ): ResponseInterface {
        $payload = ['jsonrpc' => '2.0', 'id' => $id];

        if ($error !== null) {
            $payload['error'] = $error;
        } else {
            $payload['result'] = $result;
        }

        return self::write(
            $response,
            $payload,
            $status,
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}',
        );
    }
}
