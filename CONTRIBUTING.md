# Contributing

## The one rule

The kernel must not know about a specific application. If a change needs to
mention a table, a brand or a domain concept that only one site uses, it belongs
in that site.

`tests/KernelPurityTest.php` enforces the mechanical part: no `App\` references
and no product brand inside `src/`.

## Where does my change go?

| Change | Where |
|---|---|
| Middleware, session, CSRF, mail transport, cache, i18n | `src/` |
| A new admin screen for a domain entity | the site (use `bin/make.php crud`) |
| A new theme | `themes/` in the site |
| A new writable setting | the site's `config/settings.php` |
| A fix to magic-link expiry | `src/` |
| A new starter page | `starter/` |

## Before opening a pull request

```bash
composer check   # lint + PHPStan level 6 + PHPUnit
```

A change is ready when:

- the kernel suite passes **without an application installed**;
- `composer stan` is clean: no baseline, no `@phpstan-ignore`, no cast added
  only to silence an error (`docs/TESTING.md`);
- new behaviour has a test, and a bug fix has a test that failed first;
- public classes and methods have short PHPDoc explaining the contract;
- user-facing strings are in `resources/lang` (English and at least one
  translation, kept in sync by the parity test);
- no dependency was added without a note in the pull request explaining why an
  existing one does not solve it;
- `CHANGELOG.md` has an entry under `Unreleased`.

## Style

PSR-12, `declare(strict_types=1)`, `final` by default, typed properties and
parameters. `snake_case` for database columns, `camelCase` for code, 120
columns. Comments explain **why**, not **what**.

The full version is in [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md).

## Areas that need care

- **`src/Deploy/`** writes files and touches a production database. A change
  there needs a test for the failure path, not only the happy one.
- **`src/Auth/`** is the security surface. If you touch token lifetime, session
  rotation or impersonation, say so explicitly in the pull request.
- **`src/Api/`** is what keeps REST and MCP from drifting. Never add a tool to
  `App\Mcp\ToolService`; add an `Operation`.
- **`install.php`** runs before the framework exists. It can assume nothing:
  no autoloader, no Composer, no CLI.

## Tests

- Unit tests for pure helpers.
- Integration tests for MySQL; they use a dedicated database and a transaction
  per test. Without MySQL they skip with a clear message, and CI requires them.
- Never call a real SMTP server or a paid API.

## Commits and releases

- Short, imperative, English: `Add rate limiting to magic link requests`.
- One behaviour per commit. No reformatting of unrelated lines.
- Versioning is SemVer, changed with `php bin/version.php` so `VERSION`,
  `Kernel::VERSION` and the installer constant never disagree.
- `CHANGELOG.md` gets an entry under `[Unreleased]`; the release commit moves
  it to the new version.
- The release is built with `php bin/package.php --site=starter --out=dist`,
  its SHA-256 is written into `POLARIS_RELEASE_SHA256` in `install.php`, and
  the same archive is uploaded as the `polaris-site.zip` asset. `composer check`
  verifies the pin when `dist/` exists.
- Never force-push, rewrite history, or commit generated/runtime data.
