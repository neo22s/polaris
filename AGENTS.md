# AGENTS.md — operating contract for AI agents

This file is for an AI agent working in a Polaris **site**. The same file ships
in the starter kit and is inherited by every project, so the conventions are
identical across sites.

The kernel is vendored at `kernel/` and is read-only. Everything you write goes
in `app/`, `resources/`, `config/`, `database/` or `tests/`.

## 1. What this project is

A PHP site built on the Polaris kernel: Slim 4, Twig 3, PDO/MySQL, PHPUnit.
No ORM, no Node build step, no Composer on the production server. The site is
deployed by replacing `kernel/` and uploading changed files.

## 2. Kernel vs application (the rule that matters most)

| Path | Owner | You may edit? |
|---|---|---|
| `kernel/` | the kernel repository | **No, never** |
| `app/` | this site | yes |
| `config/settings.php` | this site | yes |
| `resources/views`, `resources/lang` | this site | yes |
| `database/upgrades` | this site | yes |
| `tests/` | this site | yes |

If a kernel behaviour is wrong for this site, you **do not patch the kernel**.
Use a documented extension point (`docs/ARCHITECTURE.md`, "Extension points") or
open an issue against the kernel.

## 3. Stack

- PHP 8.1+ (`declare(strict_types=1)` everywhere), PSR-12
- Slim 4 (routing, PSR-7/PSR-15 middleware)
- Twig 3, autoescape `html`
- PDO + MySQL/MariaDB, prepared statements only
- PHPUnit 11; integration tests use a dedicated database

## 4. Layers

```
Controller  parse → validate → call a service → render. No SQL. No rules.
Service     rules, orchestration, transactions, failure isolation. No HTML.
Repository  SQL for one area. Prepared statements. No rules.
Support     pure helpers. No container access.
```

## 5. Conventions (short list, full version in `docs/CONVENTIONS.md`)

- `final` by default; typed properties, parameters and returns
- 120 columns; long HTML and translation strings exempt
- `snake_case` for columns, `camelCase` for code, `PascalCase` for classes
- Comments explain **why**; no noise comments
- No `var_dump`, `die`, `exit`, `eval`, or superglobals in classes
- Log with `[Polaris]` and context; never tokens or passwords

## 6. Security, always

- State-changing requests go through CSRF (`{{ csrf_field() }}` in every form)
- Signed-in routes: `AuthMiddleware`; admin routes: `AdminMiddleware`
- Validate input with `Validator` at the boundary
- Redirects from request data: `Url::safePath()`
- Templates autoescape; `|raw` is explicit and must have a comment
- Uploads: `ImageService`, never trust MIME or extension

## 7. Database

- One migration per change in `database/upgrades/`, filename
  `YYYYMMDDHHMMSS_description.sql`
- Idempotent SQL (`CREATE TABLE IF NOT EXISTS`, guarded `ADD COLUMN`)
- Never edit an applied migration; add a new one
- Multi-step writes use `Database::transaction()`

## 8. i18n

Every user-facing string goes through `Translator::t()` (PHP) or `t()` (Twig),
with keys in English, added to every shipped language file.

## 9. Tests

```bash
composer check   # lint + PHPUnit — the only gate
```

- New behaviour → new test
- Bug fix → a test that fails before the fix
- Integration tests reset state in `tearDown`/`finally`
- Do not call live SMTP or external paid APIs; use the fakes in `tests/Support`

## 10. Recipes (do not improvise the structure)

- New page: `docs/RECIPES.md` → "Add a page"
- New table with admin: `bin/make.php crud` (once available)
- New email: extend `Polaris\Mail\Mailer`, do not add methods to it
- New theme: `themes/<name>/views` + `THEME=<name>`
- New setting: declare it in `config/settings.php` under `writable_settings`

## 11. Definition of done

- [ ] The change lives in the right layer
- [ ] An extension point was used instead of editing the kernel
- [ ] Tests added, including a regression test for a bug fix
- [ ] Every new string is translated in all shipped locales
- [ ] `composer check` passes
- [ ] Diff contains no unrelated changes, no debug output, no secrets

## 12. Never

- Never commit, push, deploy or run destructive SQL without explicit approval
- Never put secrets in code, tests, docs or commit messages
- Never add a dependency without a written justification
- Never rewrite history or force-push

## 13. Adding an API operation (and its MCP tool)

Never write an MCP tool by hand. An operation is declared once and served twice.

```php
// app/Api/InvoiceApi.php
Operation::both(
    'create',
    'Create Invoice.',
    'POST',
    '/api/v1/invoices',
    ['number' => ['type' => 'string'], 'total' => ['type' => 'number']],
    ['number'],
    static fn (array $args): array => ['invoice' => $repository->create($args)],
)
```

That single declaration produces:

| Surface | Result |
|---|---|
| REST | `POST /api/v1/invoices` |
| MCP | tool `invoices.create` in `tools/list` |
| Docs | an entry in `/api/v1/openapi.json` and `/api/v1/docs` |

Rules:

1. One `Operation::both` per endpoint. One `Operation::tool` only for something
   that genuinely has no HTTP shape.
2. The handler is a closure over a service or repository. No SQL in the handler,
   no HTTP objects either.
3. Declare every argument in the schema. An undeclared argument is rejected with
   400, and a declared one is validated by the handler.
4. Read-only list endpoints use `Pagination::schema()` and
   `Pagination::fromArgs()`; never accept an unbounded `limit`.
5. A mutating operation needs `confirm: true` in its own arguments when deleting.
6. Add the test: call the operation through REST **and** MCP and compare the
   payloads (`tests/ApiTest.php` has the pattern).

If you are about to add a tool to `App\Mcp\ToolService`, add an operation
instead. That file only holds what has not been migrated yet.

## 14. Scaffolding

```bash
php bin/make.php crud Invoice --fields="number:string!,client:belongs_to(clients),total:decimal,status:enum(draft|sent|paid)"
```

Writes repository, API group, admin controller, two templates and an idempotent
migration. `--diff` shows what it would write; `--force` overwrites. Never run it
from the kernel repository: it refuses, because scaffolding belongs to the site.
