# AGENTS.md — how to work in this site

This file is the operating contract for an AI agent working here. It is written
to be read *before* any code is written, and updated when a convention changes.

The kernel is vendored at `kernel/` and is read-only. Everything you write goes
in `app/`, `resources/`, `config/`, `database/` or `tests/`.

## 1. What this project is

A site built on the Polaris kernel: Slim 4, Twig 3, PDO/MySQL, PHPUnit 11.
No ORM, no Node build step, no Composer on the production server. The site is
deployed by replacing `kernel/` and uploading changed files.

## 2. The boundary rule

| Path | Owner | Edit? |
|---|---|---|
| `kernel/` | the kernel repository | **never** |
| `app/`, `config/`, `resources/`, `database/`, `tests/` | this site | yes |

If the kernel does something wrong for this site, use an extension point
(`kernel/docs/ARCHITECTURE.md` → "Extension points") or open an issue. **Do not patch
`kernel/`.** An upgrade replaces it, so the patch disappears — usually in the
middle of a deploy.

## 3. Stack and versions

- PHP 8.1+ (`declare(strict_types=1)` everywhere)
- Slim 4 (routing, PSR-7/PSR-15 middleware)
- Twig 3, `autoescape: html`
- PDO + MySQL/MariaDB, prepared statements only
- PHPUnit 11; integration tests use a dedicated database

## 4. Layers

```
Controller  parse → validate → call a service → render. No SQL. No rules.
Service     rules, orchestration, transactions, failure isolation. No HTML.
Repository  SQL for one area. Prepared statements. No rules.
Support     pure helpers. No container access.
```

## 5. Conventions

- `final` by default; typed properties, parameters and return values
- 120 columns; long HTML and translation strings exempt
- `snake_case` for database columns, `camelCase` for code, `PascalCase` for classes
- Comments explain **why**; delete comments that restate the code
- No `var_dump`, `die`, `exit`, `eval`, or superglobals in classes
- Log with `[Polaris]` plus context; never tokens or passwords

## 6. Security, always

- Every state-changing form includes `{{ csrf_field() }}`
- Signed-in routes: `AuthMiddleware`; admin routes: `AdminMiddleware`
- Validate with `Validator` at the boundary, assert types in the service
- Redirects from request data: `Url::safePath()`
- Templates autoescape; `|raw` needs a comment saying why the value is trusted
- Uploads go through `ImageService`; never trust MIME type or extension

## 7. Database

- One migration per change in `database/upgrades/YYYYMMDDHHMMSS_description.sql`
- Idempotent SQL (`CREATE TABLE IF NOT EXISTS`, guarded `ADD COLUMN`)
- Never edit an applied migration; add a new one
- Multi-step writes use `Database::transaction()`

## 8. i18n

Every user-facing string goes through `Translator::t()` (PHP) or `t()` (Twig),
with an English key added to every shipped language file.

## 9. Tests

```bash
composer check   # lint + PHPUnit — the only gate
```

- New behaviour → new test
- Bug fix → a test that failed before the fix
- Integration tests clean up in `tearDown`/`finally`
- Never call a real SMTP server or a paid API; use the fakes in `tests/Support`

## 10. Documentation

The kernel ships the reference: `kernel/docs/ARCHITECTURE.md`,
`CONVENTIONS.md`, `API.md`, `DEPLOY.md`, `INSTALL.md`, `ADDING-A-LANGUAGE.md`,
`RECIPES.md`, `UPGRADING.md`. Read the one that matches the task before inventing
a pattern. `docs/AI-PROMPT.md` in this site is the prompt that started it.

## 11. Adding an API operation (and its MCP tool)

**Never write an MCP tool by hand.** Declare the operation once:

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

That one declaration gives you:

| Surface | Result |
|---|---|
| REST | `POST /api/v1/invoices` |
| MCP | tool `invoices.create` in `tools/list` |
| Docs | an entry in `/api/v1/openapi.json` and `/api/v1/docs` |

Rules:

1. One `Operation::both` per endpoint. `Operation::tool` only for something with
   no HTTP shape.
2. The handler is a closure over a service or repository — no SQL, no HTTP objects.
3. Declare every argument. An undeclared one is rejected with 400.
4. List endpoints use `Pagination::schema()` and `Pagination::fromArgs()`.
5. A deleting operation requires `confirm: true` in its own arguments.
6. Test it through REST **and** MCP and compare the payloads.

If you are about to add a tool to `App\Mcp\ToolService`, add an operation instead.

## 12. Scaffolding

```bash
php bin/make.php crud Invoice --fields="number:string!,client:belongs_to(clients),total:decimal,status:enum(draft|sent|paid)"
```

Writes a repository, an API group, an admin controller, two templates and an
idempotent migration. `--diff` prints without writing; `--force` overwrites.
It refuses to run inside `kernel/`.

## 13. Definition of done

- [ ] The change lives in the right layer
- [ ] An extension point was used instead of editing the kernel
- [ ] Tests added, including a regression test for a bug fix
- [ ] Every new string is translated in all shipped locales
- [ ] `composer check` passes
- [ ] No unrelated changes, no debug output, no secrets

## 14. Never

- Never commit, push, deploy or run destructive SQL without explicit approval
- Never put secrets in code, tests, docs or commit messages
- Never add a dependency without a written justification
- Never rewrite history or force-push
