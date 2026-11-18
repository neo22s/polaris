# The API, and why MCP comes for free

One operation, two surfaces. A REST endpoint and an MCP tool are the same thing
with a different transport, so Polaris describes each operation **once**.

## The shape

```
app/Api/UserGroup.php          one file per area
        │
        └── Operation::both('search', 'Find users', 'GET', '/api/v1/users', schema, handler)
                 │
                 ├── REST        GET /api/v1/users?q=jane     (generated route)
                 ├── MCP         tools/call users.search       (generated tool)
                 └── OpenAPI     /api/v1/openapi.json          (generated document)
```

```php
final class UserApi
{
    public static function group(UserRepository $users): ApiGroup
    {
        return new ApiGroup('users', [
            Operation::both(
                'search',
                'Find active users by text, country or city.',
                'GET',
                '/api/v1/users',
                [
                    'q' => ['type' => 'string', 'description' => 'Free text'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ],
                [],
                static fn (array $args): array => ['users' => $users->search($args)],
            ),
        ]);
    }
}
```

Register it once:

```php
$api = new Registry([
    UserApi::group($container->get(UserRepository::class)),
]);

RestRouter::mount($app, $api, '/api/v1', [ApiKeyMiddleware::class]);
$app->post('/api/mcp', \Polaris\Api\McpController::class . ':handle')
    ->add(new ApiKeyMiddleware($settings, $limiter, 'mcp'));
$app->get('/api/v1/openapi.json', DocsController::class . ':openApi')
    ->add(ApiKeyMiddleware::class); // the document lists every operation
```

Add an operation to the group and you have: a REST route, an MCP tool, and an
entry in the OpenAPI document. No second file, no second schema, no drift.

## What is generated, exactly

| Output | From | Notes |
|---|---|---|
| REST route | `httpMethod` + `path` | Mounted per operation, no controller |
| MCP tool | name + description + schema | Qualified `group.name`, unique per server |
| OpenAPI path | path + properties | Path placeholders become path parameters |
| OpenAPI request body | properties for POST/PUT/PATCH | `required` copied from the operation |
| Docs page | everything above | Dependency free, works without JavaScript |

## Argument handling

The same rules for both surfaces, because there is one implementation:

- **Path placeholders** (`{id}`) come from the route.
- **GET** reads the rest from the query string, cast to the schema type
  (`?limit=10` reaches the handler as `10`, not `"10"` — otherwise `LIMIT :limit`
  fails on a server-side prepared statement).
- **POST/PUT/PATCH** read the parsed body, so JSON and form posts both work.
- **Undeclared arguments are rejected** with a 400 / JSON-RPC `-32602`. An
  argument nobody declared is a caller bug, and silently ignoring it hides the
  bug until production.
- A missing required argument is rejected before the handler runs.

The handler receives a plain array. No DTO layer, no annotations, no reflection:
the schema is data, and the handler is a closure over a service.

## What stays out

A handler is business logic plus a call to a service. If an endpoint needs a
redirect, a file download, an HTML page or a different status code, it is a page,
not an API operation, and it belongs in a controller. Keeping that line sharp is
what stops the "API" from becoming a second web app.

## Authentication

| Surface | Header | Notes |
|---|---|---|
| REST | `X-API-KEY` | 401 with a JSON body |
| MCP | `X-MCP-API-KEY` | keeps the JSON-RPC error envelope |

Both compare with `hash_equals` and rate-limit per key. Keys live in the
environment, never in the database, so a database dump cannot leak them.

## Documentation

- `/api/v1/openapi.json` — OpenAPI 3.1, generated on every request from the
  registry, so it cannot be stale.
- `/api/v1/docs` — a readable table of the same data, no CDN, no build step.

## Pagination

Declare `Pagination::schema()` on a list operation and use it in the handler:

```php
static function (array $args) use ($repository): array {
    $page = $repository->page(Pagination::fromArgs($args));

    return ['invoices' => $page['rows'], 'pagination' => $page['pagination']];
}
```

- `limit` is clamped to 200; asking for more returns 200, not a timeout.
- The repository asks for one row more than the page and `apply()` trims it, so
  `has_more` costs nothing extra. No `COUNT(*)` on every request.
- `offset` paging cannot see rows inserted between requests. A client that needs
  a stable walk uses the id it last saw.

Response shape:

```json
{"invoices": [], "pagination": {"limit": 50, "offset": 0, "has_more": false, "next_offset": null}}
```

## Scaffolding

```bash
php bin/make.php crud Invoice --fields="number:string!,client:belongs_to(clients),total:decimal,status:enum(draft|sent|paid)"
```

Generates repository, API group (five operations, REST + MCP), admin controller,
two templates and an idempotent migration. `--diff` prints without writing;
`--force` overwrites. It refuses to run inside the kernel repository.

## Adding an operation: the checklist

1. Add an `Operation::both(...)` to the group, with its JSON Schema.
2. Write the handler as a closure over a service.
3. Add a test that calls it through **both** surfaces; the shared test in
   `tests/ApiTest.php` shows the pattern.
4. Run `composer check`.

If you are tempted to write an MCP tool by hand, the answer is no: it will drift.
