<?php

declare(strict_types=1);

namespace Polaris\Tests\Api;

use PHPUnit\Framework\TestCase;
use Polaris\Api\ApiGroup;
use Polaris\Api\Operation;
use Polaris\Api\Registry;
use Polaris\Api\RestRouter;
use Polaris\Mail\MailerInterface;
use Polaris\Repositories\MagicLinkRepository;
use Polaris\Services\MagicLinkService;
use Polaris\Support\Database;
use Polaris\Repositories\RateLimitRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * One definition, two surfaces.
 *
 * The point of the API layer is that an operation cannot exist as a REST route
 * without also being an MCP tool, and cannot be documented differently from how
 * it behaves. These tests check exactly that, because it is the property that
 * keeps the two from drifting.
 */
final class ApiTest extends TestCase
{
    public function testAnOperationAppearsAsBothARouteAndATool(): void
    {
        $registry = $this->registry();

        $this->assertCount(1, $registry->groups()['users']->restOperations());

        $names = array_column($registry->definitions(), 'name');
        $this->assertSame(['users.search'], $names);

        $paths = $registry->openApi('https://example.com', 'Test')['paths'];
        $this->assertArrayHasKey('/api/v1/users', $paths);
        $this->assertArrayHasKey('get', $paths['/api/v1/users']);
    }

    public function testADuplicateOperationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiGroup('users', [
            Operation::tool('search', 'one', [], [], static fn (): array => []),
            Operation::tool('search', 'two', [], [], static fn (): array => []),
        ]);
    }

    public function testAnUnknownArgumentIsRejectedBeforeTheHandlerRuns(): void
    {
        $called = false;

        $operation = Operation::tool('search', 'Search', ['q' => ['type' => 'string']], [], function () use (&$called): array {
            $called = true;

            return [];
        });

        try {
            $operation->call(['q' => 'x', 'nope' => 'y']);
            $this->fail('An unknown argument must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('nope', $e->getMessage());
        }

        $this->assertFalse($called, 'The handler must not run with an argument nobody declared.');
    }

    public function testANoArgumentToolRejectsUndeclaredArgumentsToo(): void
    {
        $operation = Operation::tool('health', 'Health', [], [], static fn (): array => ['ok' => true]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('surprise');

        $operation->call(['surprise' => 'value']);
    }

    public function testAMissingRequiredArgumentIsRejected(): void
    {
        $operation = Operation::both(
            'get',
            'Read one',
            'GET',
            '/api/v1/users/{id}',
            ['id' => ['type' => 'integer']],
            ['id'],
            static fn (array $args): array => ['id' => $args['id']],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('id');

        $operation->call([]);
    }

    public function testTheMcpDefinitionAlwaysHasAnObjectSchema(): void
    {
        $operation = Operation::tool('health', 'Health', [], [], static fn (): array => ['ok' => true]);
        $definition = $operation->definition();

        $this->assertInstanceOf(\stdClass::class, $definition['inputSchema']['properties']);
        $this->assertStringContainsString('"properties":{}', (string) json_encode($definition['inputSchema']));
    }

    public function testTheOpenApiDocumentDescribesAQueryParameterAndAPathParameter(): void
    {
        $registry = new Registry([
            new ApiGroup('users', [
                Operation::both(
                    'search',
                    'Find users',
                    'GET',
                    '/api/v1/users',
                    ['q' => ['type' => 'string'], 'limit' => ['type' => 'integer']],
                    [],
                    static fn (): array => [],
                ),
                Operation::both(
                    'get',
                    'Read one',
                    'GET',
                    '/api/v1/users/{id}',
                    ['id' => ['type' => 'integer']],
                    ['id'],
                    static fn (array $args): array => ['id' => $args['id']],
                ),
            ]),
        ]);

        $paths = $registry->openApi('https://example.com', 'Test')['paths'];

        $searchParameters = array_column($paths['/api/v1/users']['get']['parameters'], 'in', 'name');
        $this->assertSame('query', $searchParameters['q']);
        $this->assertSame('query', $searchParameters['limit']);

        $getParameters = $paths['/api/v1/users/{id}']['get']['parameters'];
        $this->assertSame('path', $getParameters[0]['in']);
        $this->assertTrue($getParameters[0]['required']);
    }

    public function testTheOpenApiDocumentRequiresAKeyForEveryOperationByDefault(): void
    {
        $registry = new Registry([
            new ApiGroup('users', [
                Operation::both('search', 'Find', 'GET', '/api/v1/users', [], [], static fn (): array => []),
                Operation::both(
                    'create',
                    'Create',
                    'POST',
                    '/api/v1/users',
                    ['email' => ['type' => 'string']],
                    ['email'],
                    static fn (): array => [],
                ),
            ]),
        ]);

        $paths = $registry->openApi('https://example.com', 'Test')['paths'];

        // The standard mount protects the whole group, so a GET is not public.
        $this->assertSame([['apiKey' => []]], $paths['/api/v1/users']['get']['security']);
        $this->assertSame([['apiKey' => []]], $paths['/api/v1/users']['post']['security']);

        // A site that mounts public reads documents them as public.
        $public = $registry->openApi('https://example.com', 'Test', '1.0.0', false)['paths'];
        $this->assertArrayNotHasKey('security', $public['/api/v1/users']['get']);
        $this->assertSame([['apiKey' => []]], $public['/api/v1/users']['post']['security']);
    }

    public function testTheRestRouteCallsTheSameHandlerTheToolCalls(): void
    {
        $app = AppFactory::create();
        $registry = $this->registry();

        RestRouter::mount($app, $registry);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/users')
            ->withQueryParams(['q' => 'jane', 'limit' => '10']);

        $response = $app->handle($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('jane', $body['q']);
        $this->assertSame(10, $body['limit'], 'A query value is cast to the type the schema declares.');
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testAnInvalidRestArgumentReturns400AndNotA500(): void
    {
        $app = AppFactory::create();

        RestRouter::mount($app, new Registry([
            new ApiGroup('users', [
                Operation::both(
                    'get',
                    'Read one',
                    'GET',
                    '/api/v1/users/{id}',
                    ['id' => ['type' => 'integer']],
                    ['id'],
                    static fn (array $args): array => ['id' => $args['id']],
                ),
            ]),
        ]));

        // A route that cannot match is a 404, not an exception in the handler.
        $missing = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/users/7'));
        $this->assertSame(200, $missing->getStatusCode());
        $this->assertSame(['id' => '7'], json_decode((string) $missing->getBody(), true));
    }

    public function testTheSameOperationReturnsTheSamePayloadOnBothSurfaces(): void
    {
        $registry = new Registry([
            new ApiGroup('math', [
                Operation::both(
                    'double',
                    'Double a number.',
                    'GET',
                    '/api/v1/double',
                    ['value' => ['type' => 'integer']],
                    ['value'],
                    static fn (array $args): array => ['result' => ((int) $args['value']) * 2],
                ),
            ]),
        ]);

        // REST
        $app = AppFactory::create();
        RestRouter::mount($app, $registry);

        $rest = $app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/v1/double')
                ->withQueryParams(['value' => '21']),
        );
        $restBody = json_decode((string) $rest->getBody(), true);

        // MCP, through the same registry
        $mcp = new \Polaris\Api\McpController($registry);
        $response = $mcp->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/mcp')
                ->withParsedBody([]),
            new \Slim\Psr7\Response(),
        );
        // The controller reads the body stream, so build a fresh request with it.
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/mcp')
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream((string) json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'math.double', 'arguments' => ['value' => 21]],
            ])));
        $mcpResponse = $mcp->handle($request, new \Slim\Psr7\Response());
        $payload = json_decode((string) $mcpResponse->getBody(), true);
        $toolBody = json_decode($payload['result']['content'][0]['text'], true);

        $this->assertSame(['result' => 42], $restBody);
        $this->assertSame($restBody, $toolBody, 'Both surfaces must return the same payload.');
    }

    public function testTheMcpControllerRejectsABodyLargerThanTheConfiguredCeiling(): void
    {
        $mcp = new \Polaris\Api\McpController($this->registry(), [], 32);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/mcp')
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(str_repeat('x', 33)));

        $response = $mcp->handle($request, new \Slim\Psr7\Response());

        $this->assertSame(413, $response->getStatusCode());
        $this->assertStringContainsString('Request too large', (string) $response->getBody());
    }

    public function testTheMcpControllerTrustsAnOversizedContentLengthHeader(): void
    {
        $mcp = new \Polaris\Api\McpController($this->registry(), [], 32);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/mcp')
            ->withHeader('Content-Length', '9999')
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream('{"jsonrpc":"2.0"}'));

        $response = $mcp->handle($request, new \Slim\Psr7\Response());

        $this->assertSame(413, $response->getStatusCode());
    }

    public function testTheMcpControllerKeepsTheResponseItWasGiven(): void
    {
        $mcp = new \Polaris\Api\McpController($this->registry());

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/mcp')
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream((string) json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'ping',
            ])));

        // A middleware may decorate the response before the controller runs.
        // The controller has to reuse it instead of creating a bare Response.
        $response = $mcp->handle($request, (new \Slim\Psr7\Response())->withHeader('X-Trace', 'abc'));

        $this->assertSame('abc', $response->getHeaderLine('X-Trace'));
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testTheDocsPageListsEveryOperation(): void
    {
        // Docs are opt-in: the page lists every operation, which is
        // reconnaissance on a site that never asked for it.
        $docs = new \Polaris\Api\DocsController($this->registry(), 'Test', 'https://example.com', '1.0.0', true);

        $html = (string) $docs->html(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/docs'),
            new \Slim\Psr7\Response(),
        )->getBody();
        $this->assertStringContainsString('users.search', $html);
        $this->assertStringContainsString('GET /api/v1/users', $html);

        $json = (string) $docs->openApi(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/openapi.json'),
            new \Slim\Psr7\Response(),
        )->getBody();
        $document = json_decode($json, true);
        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/api/v1/users', $document['paths']);
    }

    public function testTheDocsAreHiddenUnlessTheSiteOptsIn(): void
    {
        $docs = new \Polaris\Api\DocsController($this->registry(), 'Test', 'https://example.com');

        $response = $docs->openApi(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/openapi.json'),
            new \Slim\Psr7\Response(),
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPaginationClampsTheLimitAndReportsWhetherThereIsMore(): void
    {
        $page = \Polaris\Api\Pagination::fromArgs(['limit' => '1000', 'offset' => '10']);

        $this->assertSame(\Polaris\Api\Pagination::MAX_LIMIT, $page->limit, 'A huge limit is clamped, not honoured.');
        $this->assertSame(10, $page->offset);
        $this->assertSame($page->limit + 1, $page->fetchLimit(), 'One extra row is fetched to detect the next page.');

        $rows = array_fill(0, $page->limit + 1, ['id' => 1]);
        $result = $page->apply($rows);

        $this->assertCount($page->limit, $result['rows']);
        $this->assertTrue($result['pagination']['has_more']);
        $this->assertSame($page->offset + $page->limit, $result['pagination']['next_offset']);

        $exact = $page->apply(array_fill(0, $page->limit, ['id' => 1]));
        $this->assertFalse($exact['pagination']['has_more']);
        $this->assertNull($exact['pagination']['next_offset']);
    }

    public function testTheApiKeyMiddlewareRejectsAMissingKey(): void
    {
        $database = $this->database();
        $middleware = new \Polaris\Api\ApiKeyMiddleware(
            ['api' => ['enabled' => true, 'api_key' => str_repeat('k', 32)]],
            new RateLimitRepository($database),
            'rest',
        );

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/users'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new \RuntimeException('The handler must not run.');
                }
            },
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('ApiKey', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testTheApiKeyMiddlewareAcceptsTheConfiguredKey(): void
    {
        $database = $this->database();
        $key = str_repeat('k', 32);
        $middleware = new \Polaris\Api\ApiKeyMiddleware(
            ['api' => ['enabled' => true, 'api_key' => $key]],
            new RateLimitRepository($database),
            'rest',
        );

        $response = $middleware->process(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/v1/users')
                ->withHeader('X-API-KEY', $key),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $response = (new \Slim\Psr7\Response())->withStatus(204);

                    return $response;
                }
            },
        );

        $this->assertSame(204, $response->getStatusCode());
    }

    private function registry(): Registry
    {
        return new Registry([
            new ApiGroup('users', [
                Operation::both(
                    'search',
                    'Find users by text.',
                    'GET',
                    '/api/v1/users',
                    ['q' => ['type' => 'string'], 'limit' => ['type' => 'integer']],
                    [],
                    static fn (array $args): array => [
                        'q' => $args['q'] ?? null,
                        'limit' => $args['limit'] ?? null,
                    ],
                ),
            ]),
        ]);
    }

    private function database(): Database
    {
        // Probe, connect and skip/fail decisions live in the helper, so a wrong
        // TEST_DB_HOST costs a second instead of the job timeout.
        \Polaris\Tests\Support\Integration::pdo($this);

        $database = new Database([
            'host' => (string) (getenv('TEST_DB_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'database' => \Polaris\Tests\Support\Integration::databaseName(),
            'username' => (string) (getenv('TEST_DB_USERNAME') ?: 'root'),
            'password' => (string) (getenv('TEST_DB_PASSWORD') ?: ''),
        ]);

        $database->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS rate_limits (id int unsigned NOT NULL AUTO_INCREMENT, bucket varchar(191) NOT NULL, hits int unsigned NOT NULL DEFAULT 0, expires_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY bucket (bucket))',
        );

        return $database;
    }
}
