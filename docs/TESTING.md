# Testing and static analysis

The gate is one command:

```bash
composer check   # lint -> phpstan -> phpunit
```

CI runs the same gate on PHP 8.1, 8.2, 8.3 and 8.4, plus the starter project
and a release build. A skipped integration test is a **failure** in CI:
`POLARIS_REQUIRE_INTEGRATION=1` turns the skip into an error, so a database that
is down cannot look like a passing suite.

## What each layer covers

| Command | What it proves |
|---|---|
| `composer lint` | every PHP file parses, including `bin/`, `resources/stubs/` and the entry points |
| `composer stan` | PHPStan level 6 over `src/`: types, dead conditions, unreachable arms |
| `composer test` | behaviour, with `failOnWarning` and `failOnRisky` |
| `composer test:integration` | the same suite with MySQL required rather than skipped |

## Test layout

```
tests/
  Api/ApiTest.php          REST and MCP return the same payload for one operation
  AuthTest.php             magic links, sessions, roles, impersonation
  DeployTest.php           archive hygiene, config parsing, runner token
  Cli/CrudGeneratorTest.php   generated files parse and keep their contract
  Support/Integration.php  skip-in-local / fail-in-CI database helper
  Support/RestoresTheTranslator.php  locale leak guard for tests
  ...
```

Rules that the suite already enforces, and a new test should not weaken:

- the kernel suite passes **without an application installed** (`KernelBootTest`);
- `src/` never references `App\` or a product brand (`KernelPurityTest`);
- `VERSION`, `Kernel::VERSION` and the installer constant agree;
- the installer checksum matches the built release when `dist/` exists;
- every shipped language has the same key set;
- no template uses `|raw` without a reason.

## Writing a test

- Name it as a sentence: `testAMagicLinkCanOnlyBeConsumedOnce`, not `testAuth2`.
- Test the behaviour, not the implementation: assert the payload, the redirect,
  the stored row or the exception message.
- A bug fix starts with a test that fails on the old code. Keep it.
- Use the fakes in `tests/Support`; never call SMTP, FTP or a paid API.
- Reset global state in `tearDown` (`Config::reset()`, `Env::reset()`,
  `Translator` restoration, `$_SESSION`). A leaking test is a random failure
  later.
- Integration tests use a dedicated database, a transaction per test and clean
  up in `finally`.

## Static analysis

PHPStan level 6 is the floor, not the ceiling. When it reports a condition that
can never be true, that is usually a real bug: it caught a dead `if` in
`Database::transaction()` and an impossible branch in the image service.

Two rules that keep the analysis honest:

- fix the code, do not add a baseline or a `@phpstan-ignore`;
- do not widen a type or cast just to silence an error. Narrow the type at the
  source.

The configuration is `phpstan.neon` (and `starter/phpstan.neon` for a site).
`install.php` is excluded because it runs before the autoloader exists; it is
still linted by `composer lint`.

## The missing layer: a real site

The kernel suite is green when the kernel is consistent with itself. It is not
evidence that a site boots it. Two integrations bugs in the 0.2.x line were only
visible through the real application:

- POST under `/api/` reached CSRF before the key middleware, so a client got an
  HTML redirect instead of `401 JSON`;
- the site's MCP controller read `getContents()` from whatever position the
  stream was in, so a body already consumed looked empty.

ModelPolas ships `composer smoke` for exactly this. It boots `app/bootstrap.php`
and drives requests through the real middleware stack:

```bash
cd ../modelpolas
composer smoke
```

A kernel change is done when the kernel suite and the site smoke test both pass.
See `CONTRIBUTING.md`, "Prove it on a real site".

## Continuous integration

`.github/workflows/tests.yml` runs three jobs on every push and pull request:

- **phpunit** on PHP 8.1–8.4, with MySQL and `POLARIS_REQUIRE_INTEGRATION=1`;
- **starter**, which links `kernel/` the way a real site does and runs its own
  `composer check`;
- **package**, which builds the site release and asserts it is complete and
  clean.

Both dependency jobs also run `composer validate --strict` and
`composer audit --no-dev`. A release whose manifest or dependency tree is wrong
should fail before the archive is built.
