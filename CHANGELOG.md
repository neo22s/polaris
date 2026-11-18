# Changelog

All notable changes to Polaris are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- **Tests.** 146 kernel tests, including guards for kernel purity, version
  consistency, mail MIME, API parity and deploy safety.

### Notes

- No ORM. Rows are arrays and repositories own their SQL.
- 19 runtime packages, about 4.5 MB of vendor, no Node build step, no Composer
  on the server.
- Known collision: `polaris/core` by 2am.tech declares the same `Polaris\`
  namespace. Do not install both; see `TRADEMARK.md`.
