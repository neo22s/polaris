# Conventions

Rules a change has to follow. They exist so that every Polaris project looks
like the same project months later, written by a different person or an AI
agent.

## PHP

- `declare(strict_types=1);` in every file.
- PSR-12: 4 spaces, one class per file, braces on their own line, one blank line
  between methods.
- `final` by default. Leave a class open only when it is a documented extension
  point (`Mailer`, `AuthService`, `UserRepository`).
- Type every property, parameter and return value. Use `mixed` only when the
  value is genuinely unconstrained.
- Constructor property promotion for dependencies.
- `snake_case` for database columns, `camelCase` for methods and variables,
  `PascalCase` for classes.
- 120 columns where practical. Long HTML and translation strings are exempt.
- No `var_dump`, `print_r`, `die`, `exit` or `eval` in `src/`.
- `composer check` runs syntax lint, PHPStan level 6 and PHPUnit. Lint does
  **not** enforce PSR-12 formatting; the conventions above are review rules,
  not a formatter, and PHPStan catches type and dead-code mistakes rather than
  layout. See [`TESTING.md`](TESTING.md).
- A line over 120 columns is split unless it is generated code, a heredoc
  scaffold, or a translation literal.
- No superglobals in classes; session state goes through `Polaris\Support\Session`.
- `random_bytes()` or `hash()` for anything security related. Never `rand`,
  `mt_rand`, `md5` or `sha1`.
- Log with the `[Polaris]` prefix and enough context to debug, never with tokens
  or passwords.
- Filesystem calls that may fail go through `Polaris\Support\SafeFilesystem`.
  It returns the same values as PHP but logs failures, so a shared-hosting
  permission error is tolerated without being invisible. A bare `@` needs a
  comment saying why that particular call is an expected failure.

## Comments

Explain **why**, not what. Document:

- the responsibility of a class, in one or two lines;
- array shapes crossing a boundary (`array{user:?array,error:?string}`);
- external API quirks, security decisions, expiry windows, failure isolation.

Do not document what the next line obviously does.

## Layers in practice

```
Controller  -> parse, validate, call, render. No SQL. No business rules.
Service     -> rules, transactions, failure isolation. No HTML.
Repository  -> SQL. Prepared statements only.
Support     -> pure helpers. No container access.
```

If a controller needs a query it asks a repository. If a repository needs a rule
it belongs in a service and the controller calls it.

## Views

- Autoescape stays on; never disable it for a whole template.
- `|raw` needs a comment saying why the value is trusted.
- No arithmetic in templates: compute in the controller or service. It keeps
  the template readable and avoids type surprises.
- Overrides go in `resources/views` or a theme, never in `kernel/resources/views`.

## Database

- Every query uses bound parameters, including `LIMIT` and `OFFSET`.
- A multi-step write uses `Database::transaction()`; nested calls become
  savepoints.
- Kernel migrations go to `kernel/database/upgrades/`, application migrations to
  `database/upgrades/`. Both are idempotent and never edited after being
  applied.
- New indexes come with the query that needs them, in the same migration.

## Security

- All state-changing requests pass CSRF. The only exceptions are routes under
  `/api/`, which must mount an API-key middleware, and requests carrying a
  valid key header. Never mount an unauthenticated form under `/api/`.
- Authenticated routes use `AuthMiddleware`; admin routes add `AdminMiddleware`.
- Validate at the boundary with `Validator`, then assert types in the service.
- Redirects built from request data go through `Url::safePath()`.
- Uploads are validated by real content and re-encoded; never trust a MIME
  header or an extension.

## Internationalisation

- Every user-facing string goes through `Translator::t()` (PHP) or `t()` (Twig).
- Keys are namespaced (`mail.*`, `validation.*`) and in English.
- Every key exists in every shipped language file; `KernelPurityTest` compares
  the key sets.

## Static analysis

- PHPStan level 6 on `src/` is part of `composer check`. No baseline, no
  `@phpstan-ignore`, no cast added only to quiet an error: narrow the type at
  the source.
- `array<string,mixed>` on a repository result is a contract, not noise: write
  the shape down and let the caller rely on it.

## Tests

- New behaviour, new test. Bug fix, a test that failed first.
- Unit tests for pure helpers, integration tests for anything touching MySQL.
- Integration tests use a dedicated database, a transaction per test and fakes
  for email and uploads. They never call a real server.
- The kernel suite must pass with no application installed.

## The boundary rule

Before adding anything to the kernel, ask: *would two unrelated sites need
this?* If the answer is "maybe one will", it goes in the application.

`tests/KernelPurityTest.php` enforces the mechanical part: no `App\`
references, no product brand in `src/`.

## Commits

Short, imperative, English: `Add rate limiting to magic link requests`.
One behaviour per commit. No reformatting of unrelated lines.
