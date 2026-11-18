<?php

declare(strict_types=1);

namespace Polaris\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the generated OpenAPI document and a human-readable page.
 *
 * Both read the same Registry the endpoints do, so the documentation cannot
 * describe an endpoint that no longer exists. The HTML page is deliberately
 * dependency free: no Swagger UI, no CDN, no build step. A JSON document is
 * optional to browse; this is a fallback that works with `curl` and in a
 * browser on a plane.
 */
final class DocsController
{
    /**
     * @param bool $enabled False returns 404: the document lists every
     *        operation, which is reconnaissance on a site that has not opted in.
     */
    public function __construct(
        private Registry $registry,
        private string $title,
        private string $serverUrl,
        private string $version = '1.0.0',
        private bool $enabled = false,
    ) {
    }

    public function openApi(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->enabled) {
            return $this->notFound($response);
        }

        $document = $this->registry->openApi($this->serverUrl, $this->title, $this->version);

        $response->getBody()->write((string) json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function html(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->enabled) {
            return $this->notFound($response);
        }

        $operations = [];

        foreach ($this->registry->groups() as $groupName => $group) {
            foreach ($group->operations() as $operation) {
                $operations[] = [
                    'group' => $groupName,
                    'name' => $operation->name,
                    'description' => $operation->description,
                    'http' => $operation->hasRestBinding()
                        ? $operation->httpMethod . ' ' . $operation->path
                        : 'MCP only',
                    'properties' => array_keys($operation->properties),
                    'required' => $operation->required,
                ];
            }
        }

        $response->getBody()->write($this->render($operations));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['status' => 404, 'message' => 'Not found'],
        ]));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }

    /**
     * @param array<int,array{group:string,name:string,description:string,http:string,properties:array<int,string>,required:array<int,string>}> $operations
     */
    private function render(array $operations): string
    {
        $rows = '';

        foreach ($operations as $operation) {
            $properties = htmlspecialchars(implode(', ', $operation['properties']) ?: '—', ENT_QUOTES);
            $required = htmlspecialchars(implode(', ', $operation['required']) ?: '—', ENT_QUOTES);

            $rows .= '<tr>'
                . '<td><code>' . htmlspecialchars($operation['group'] . '.' . $operation['name'], ENT_QUOTES) . '</code></td>'
                . '<td><code>' . htmlspecialchars($operation['http'], ENT_QUOTES) . '</code></td>'
                . '<td>' . htmlspecialchars($operation['description'], ENT_QUOTES) . '</td>'
                . '<td><code>' . $properties . '</code></td>'
                . '<td><code>' . $required . '</code></td>'
                . '</tr>';
        }

        $title = htmlspecialchars($this->title, ENT_QUOTES);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$title} API</title>
<style>
    body { margin:0; font-family: system-ui,-apple-system,"Segoe UI",Arial,sans-serif; color:#111; background:#fafafa; }
    main { max-width: 70rem; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
    h1 { font-size:1.5rem; margin:0 0 .25rem; }
    p.sub { color:#666; margin:0 0 2rem; }
    table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e5e5; border-radius:8px; overflow:hidden; font-size:.9rem; }
    th { text-align:left; padding:.7rem .8rem; background:#f5f5f5; font-size:.75rem; text-transform:uppercase; letter-spacing:.06em; color:#555; }
    td { padding:.65rem .8rem; border-top:1px solid #eee; vertical-align:top; }
    code { font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.85rem; }
    .note { margin-top:1.5rem; color:#666; font-size:.85rem; }
</style>
</head>
<body>
<main>
    <h1>{$title} API</h1>
    <p class="sub">
        Every operation below is served twice from one definition: as REST and as an MCP tool.
        The machine-readable version is at <a href="openapi.json">openapi.json</a>.
    </p>
    <table>
        <thead>
            <tr><th>Operation</th><th>HTTP</th><th>Description</th><th>Arguments</th><th>Required</th></tr>
        </thead>
        <tbody>{$rows}</tbody>
    </table>
    <p class="note">
        Authenticate with <code>X-API-KEY</code> for REST and <code>X-MCP-API-KEY</code> for MCP.
        Keys are configured in the environment and are never stored in the database.
    </p>
</main>
</body>
</html>
HTML;
    }
}
