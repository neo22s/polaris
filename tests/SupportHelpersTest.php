<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;
use Polaris\Deploy\DeployConfig;
use Polaris\Support\Clock;
use Polaris\Support\DotenvParser;
use Polaris\Support\JsonResponse;
use Polaris\Support\SafeFilesystem;
use Polaris\Support\TrustedProxy;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The small shared helpers created to remove duplicated logic. Each test pins
 * the property the duplication used to break.
 */
final class SupportHelpersTest extends TestCase
{
    public function testClockProducesOneSqlTimestampFormat(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', Clock::now());
        $this->assertSame('2026-01-02 03:04:05', Clock::at((int) strtotime('2026-01-02 03:04:05')));
        $this->assertSame(Clock::SQL_FORMAT, 'Y-m-d H:i:s');
    }

    public function testBothDotenvReadersAgreeOnTheSameGrammar(): void
    {
        $content = <<<'ENV'
# a comment
export PLAIN=value
QUOTED="a value with spaces"
LITERAL='literal # and = signs'
PASSWORD="pass=with=equals"
INLINE=public_html # trailing comment
HASH=foo#bar
CRLF=windows
ENV;

        $expected = DotenvParser::parse($content);

        $this->assertSame($expected, DeployConfig::parse($content));
        $this->assertSame([
            'PLAIN' => 'value',
            'QUOTED' => 'a value with spaces',
            'LITERAL' => 'literal # and = signs',
            'PASSWORD' => 'pass=with=equals',
            'INLINE' => 'public_html',
            'HASH' => 'foo#bar',
            'CRLF' => 'windows',
        ], $expected);
    }

    public function testJsonResponseKeepsTheRestErrorEnvelopeAndStatus(): void
    {
        $response = JsonResponse::error(new Response(), 404, 'Not found');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(
            ['error' => ['status' => 404, 'message' => 'Not found']],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function testJsonResponseKeepsTheJsonRpcEnvelope(): void
    {
        $response = JsonResponse::rpc(new Response(), 7, null, ['result' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 7, 'result' => ['result' => true]],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function testTrustedProxyOnlyBelievesForwardedProtoFromAKnownPeer(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/', ['REMOTE_ADDR' => '203.0.113.7', 'HTTPS' => 'off'])
            ->withHeader('X-Forwarded-Proto', 'https');

        $this->assertFalse((new TrustedProxy())->isSecure($request));
        $this->assertFalse((new TrustedProxy(['10.0.0.1']))->isSecure($request));
        $this->assertTrue((new TrustedProxy(['203.0.113.7']))->isSecure($request));

        $direct = (new ServerRequestFactory())->createServerRequest('GET', '/', ['HTTPS' => 'on']);
        $this->assertTrue((new TrustedProxy())->isSecure($direct));
    }

    public function testSafeFilesystemReturnsTheSameValuesAsPhpForMissingFiles(): void
    {
        $directory = sys_get_temp_dir() . '/polaris-safe-fs-' . bin2hex(random_bytes(6));

        try {
            $this->assertFalse(SafeFilesystem::read($directory . '/missing'));
            $this->assertTrue(SafeFilesystem::delete($directory . '/missing'));
            $this->assertTrue(SafeFilesystem::makeDirectory($directory));

            $file = $directory . '/value';
            $this->assertSame(5, SafeFilesystem::write($file, 'hello'));
            $this->assertSame('hello', SafeFilesystem::read($file));
            $this->assertTrue(SafeFilesystem::delete($file));
        } finally {
            if (is_dir($directory)) {
                foreach (glob($directory . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($directory);
            }
        }
    }

    public function testJsonRpcErrorsCanKeepAnHttpStatus(): void
    {
        $response = JsonResponse::rpc(new Response(), null, ['code' => -32001, 'message' => 'Invalid key'], null, 401);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(-32001, json_decode((string) $response->getBody(), true)['error']['code']);
    }
}
