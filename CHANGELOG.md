# Changelog

All notable changes to Polaris are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.3] - 2026-11-20

### Fixed

- **A bad or missing API key answers 401 JSON, not a CSRF redirect.** Before,
  `POST /api/...` without a key passed CSRF first, which redirected with
  HTML; the key middleware never got to answer. API paths are now exempt from
  CSRF by prefix, so the mounted key middleware owns the response. Non-API
  forms are still protected. Found by exercising a real site (ModelPolas)
  against the kernel.

## [0.2.2] - 2026-11-20

Dependency maintenance. No public API changed.

### Security

- **PHPMailer 6.12.0 → 7.2.0.** Upstream 7.1.0 sanitises `MessageDate`,
  `XMailer`, `ContentType`, `CharSet`, `username` and `password` before use,
  7.2.0 folds long address lines correctly and stops a `QUIT` on a closed
  connection. The 7.0 major only makes `lang()`, `setLanguage()` and
  `$language` static; the kernel sets `CharSet` and never calls them, so no
  code change was needed.
- **`actions/checkout` 4 → 7** in the test workflow.

## [0.2.1] - 2026-11-20

Tooling release: the standards that were documented are now enforced.

### Added

- **PHPStan level 6 is part of the gate.** `composer stan` analyses `src/` with
  no baseline and no ignores; `composer check` now runs lint → PHPStan → PHPUnit.
  The site's `starter/composer.json` gets the same three-step gate over `app/`
  and `config/`.
- **`docs/TESTING.md`** documents the gate, the test layout, the rules a test
  must not weaken, and the CI jobs.
- **Editor and repository hygiene:** `.editorconfig`, `.gitattributes`
  (LF for PHP, binary for archives, `export-ignore` for CI and tests),
  Dependabot for Composer and Actions, a pull-request template and issue
  templates that route security reports to `SECURITY.md`.
- **CI validates and audits the manifest** (`composer validate --strict`,
  `composer audit --no-dev`) before building, and the packaged site now ships
  `kernel/docs/` so the `AGENTS.md` it includes points at files that exist.

### Fixed

- **Real bugs PHPStan level 6 exposed:** a dead `if` in
  `Database::transaction()`, an unreachable branch in `ImageService` and an
  undefined `$http_response_header` dependency in `AcumbamailService`.
- **The starter admin panel injected a `Cache` it never used**, and two starter
  controller actions had untyped `$args`.

## [0.2.0] - 2026-11-19

Audit pass: the kernel keeps its shape, and every claim in the README now has
code behind it. Two new guards ship with it — the installer checksum is tested
against the built archive, and `uploads/.htaccess` is part of every release.

### Security

- **Magic links are consumed atomically.** The `UPDATE` only matches an unused,
  unexpired token, so two concurrent requests cannot both sign in with the same
  link.
- **Magic-link requests are rate-limited** per account and per IP address, so a
  known email cannot be used to flood an inbox or the mail quota.
- **The default CSP is strict.** No `unsafe-inline`, no `unsafe-eval` and no
  third-party hosts; a site opts into what it actually uses.
- **`X-Forwarded-Proto` is only trusted from configured proxies.** Without
  `TRUSTED_PROXIES`, the header cannot mark a request as HTTPS and forge HSTS or
  a `Secure` cookie in plain HTTP.
- **Deploy credentials survive `=`.** `.deploy.env` now parses quoted values and
  inline comments correctly, so a password containing `=` is not truncated.
- **The migration runner refuses the insecure fallback.** Calling it over HTTPS
  requires cURL with peer verification; the unverified `file_get_contents` path
  is gone.
- **`Database::insert()` rejects an empty row** instead of returning `0`, which
  looked like a valid id.
- **The generated docs sit behind the API key.** The starter mounted
  `/api/v1/docs` and `/api/v1/openapi.json` unprotected even though the document
  lists every operation and argument.
- **OpenAPI marks reads as key-protected too**, matching how `RestRouter::mount`
  applies the middleware to the whole group.
- **CSRF exemption now verifies the API key.** Merely sending an `X-API-KEY`
  header no longer disables CSRF; the value must match the configured key.
- **MCP bodies are size-limited** by `mcp.max_bytes` (4 MB default), read one byte
  past the ceiling and answered with 413 before `json_decode`.
- **No-argument tools reject undeclared arguments** via MCP, as the documented
  contract always promised.
- **Deploy tokens travel in POST bodies**, and the one-off migration runner
  deletes itself before checking the token, so neither URLs nor access logs
  carry the secret.
- **`.env` is created with `0600`**, not group-readable `0640`.
- **`uploads/.htaccess` ships in releases** and `ImageService` validates image
  bytes and re-encodes them into a new file with a random name.
- **Translation parameters are literal**, so `$1` in a user name is no longer
  interpreted as a regex backreference.

### Changed

- **MCP responses reuse the PSR-7 response** the controller receives, so
  middleware can decorate or inspect them.
- **The session cookie is renewed through the response**, not `setcookie()`,
  which keeps it inside the middleware stack.
- **REST and MCP share one API-key middleware.** The MCP mode also enforces
  `POST` and rate-limits per IP.
- **The version has one updater.** `bin/version.php` now also rewrites the
  installer constant; OpenAPI, MCP and the docs read `Kernel::VERSION` instead of
  carrying their own `1.0.0`.
- **Shared helpers replace duplication.** One `DotenvParser`, one `Clock`, one
  `JsonResponse`, one `TrustedProxy` and `SafeFilesystem` replace several copies
  of the same logic. `RateLimiter` moved to `Repositories`, session rotation and
  admin promotion moved out of `AuthService`, and the dead `View/helpers.php`
  is gone.
- **`bin/cron.php` now exists**, matching the install and recipe docs. It prunes
  the cache and runs tasks registered in `config/cron.php`.
- **`AuthDependencies` gives AuthService a one-argument entry point.**
  `AuthService::withDependencies()` is preferred; the old nine-argument
  constructor still works.
- **Scaffolds live in `resources/stubs/`.** The generator renders reviewable
  files instead of carrying six long heredocs.
- **The release archive was rebuilt and its checksum repinned.** The new zip
  includes `uploads/.htaccess`, `bin/cron.php`, `ImageService` and `Clock`.

### Fixed

- **`bin/version.php bump` validates the current version.** A precedence bug made
  the SemVer check always pass.
- **The docs no longer point at a non-existent cron runner** or claim that
  `install.php` is absent from releases.
- **`Config::get()` keys in the starter admin panel no longer include the
  `settings.` prefix**, which made both values fall back to their defaults.

## [0.1.0] - 2026-11-18

The kernel, extracted from a production application and running against it.

### Added

- **Kernel/app split.** `kernel/` is read-only and replaceable; `app/` is the
  site. One `composer.json` maps `Polaris\` and `App\`; there is no class
  shadowing, so "which class is running?" always has an answer.
- **HTTP.** Slim 4 with a documented middleware order: security headers, error
  pages, session, template globals, body parsing, CSRF, routing.
- **Authentication.** Magic links (single use, hashed at rest, 15 minutes),
  long-lived sessions, roles, impersonation. Domain rules are injected through
  `UserStore`, `ProfileProvisioner` and `SubscriberSync`.
- **Views.** Twig 3 with `autoescape: html` and a theme → app → kernel cascade.
  `View`, `TwigFactory`, `ViewGlobalsProvider` and the shared helpers.
- **i18n.** Configurable locale list, URL prefix wins over account, session and
  `Accept-Language`, per-language names, `dir` derived from the language, and a
  key-parity test.
- **API.** One `Operation` produces a REST route, an MCP tool and an OpenAPI
  entry. `Registry`, `RestRouter`, `McpController`, `DocsController` and
  `Pagination` (clamped limits, no `COUNT(*)` per request).
- **Mail.** PHPMailer transport with quoted-printable encoding, a table-based
  layout that survives Outlook and Gmail, and a plain-text alternative that
  keeps the link.
- **Data.** `Database` (typed binds, savepoint-aware transactions), repositories,
  and `MigrationRunner` with an advisory lock and idempotent SQL.
- **Deploy.** `Packager` (deterministic archive, no secrets, no dev packages),
  `SiteBuilder` (`composer install --no-dev` in a staging tree), `FtpUploader`
  (chroot-aware root detection), `RemoteRunner` (token-gated, self-deleting) and
  `DeployConfig` (credentials never live in the kernel).
- **Installer.** `install.php`: one file, checksum-verified download, zip-slip
  rejection, `.env` writing, migrations and the first admin with a printed
  login link, then self-deletes.
- **Scaffolding.** `bin/make.php crud` writes a repository, an API group, an
  admin controller, two templates and an idempotent migration.
- **Site.** `starter/`: passwordless auth, admin health panel, first-run
  `/setup`, two languages, `AGENTS.md` and the AI prompt.
- **Tests.** 159 kernel tests, including guards for kernel purity, version
  consistency, mail MIME, API parity, deploy safety and the magic-link race.

### Notes

- No ORM. Rows are arrays and repositories own their SQL.
- 19 runtime packages, about 4.5 MB of vendor, no Node build step, no Composer
  on the server.
- Known collision: `polaris/core` by 2am.tech declares the same `Polaris\`
  namespace. Do not install both; see `TRADEMARK.md`.
