# Roadmap

Status: **0.1.0** — kernel extracted and running against a production
application. The application layer and the tooling below are the next steps.

## Done

- [x] Kernel/app split with one autoloader (kernel is a plain directory)
- [x] Support helpers: database, cache, CSRF, flash, session store, rate limit,
      validator, translator, URLs, pagination, text, uploads, handles
- [x] Middleware: session, CSRF, auth, admin, guest, security headers, error pages
- [x] Passwordless auth: magic links, sessions, roles, impersonation
- [x] Mail: SMTP transport, login email, extension points for app messages
- [x] Views: Twig 3, helpers, theme/app/kernel cascade
- [x] i18n: file locales, database overrides, English fallback, parity test
- [x] Strategy: no ORM, no class shadowing, no theme uploads
- [x] Kernel purity test (no `App\` references, no product brand)

## Next

### 1. Application skeleton

- `polarisphp/starter` repository with `app/`, `resources/`, `config/`, `.htaccess`
- Web installer: requirements check, DB credentials, schema, first admin,
      self-deleting token
- `bin/package.php`: release archive with `vendor/` included and dev packages
      excluded

### 2. Code generator

- `bin/make.php crud Invoice --fields=...` writes controller, repository,
  service, views, migration and tests, in the shape the docs describe
- `--diff` shows what would change; `--force` is explicit
- Generated code is ordinary, editable code — no runtime engine

### 3. REST, OpenAPI and MCP from the same definition

- `app/Resources/*.php` descriptors drive admin screens and `/api/v1` endpoints
- OpenAPI 3.1 document and a docs page generated from the same descriptors
- API keys hashed at rest, scoped per resource, rate limited
- MCP tools built from the descriptors instead of hand-written

### 4. Updater

- Signed manifest, checksum verification, database backup, rollback
- FTP and zip upload paths, plus SSH/rsync for hosts that allow it

### 5. Themes

- `base` and `minimal` themes, documented cascade
- No upload, no sandbox: a theme is files in the repository

### 6. CRM module (reference implementation)

- Contacts, companies, deals, activities
- Built with the generator on purpose: if it takes weeks instead of days, the
  generator is not good enough yet

## Explicitly not planned

- An ORM
- A plugin marketplace
- Multi-tenancy
- GraphQL
- A page builder
- Support for PHP versions below 8.1
