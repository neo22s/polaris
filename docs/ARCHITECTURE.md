# Architecture

## Two layers, one autoloader

```
kernel/                 read-only, replaceable, MIT, domain free
  src/                  Polaris\ namespace
app/                    the site, never touched by a kernel upgrade
                        App\ namespace
```

`composer.json` maps both namespaces:

```json
"autoload": {
  "psr-4": {
    "App\\": "app/",
    "Polaris\\": "kernel/src/"
  }
}
```

There is **no class shadowing**. A site cannot override a kernel class by
dropping a file with the same name: that makes "which class is running?"
unanswerable, and it was the failure mode of the frameworks this one learns
from. To change behaviour you use a documented extension point, override a
configuration value, or copy a template into `resources/views` (the view cascade
is the one place where "first file wins" is the intended design).

## Request lifecycle

```
index.php
  Kernel::boot()
    Env::load(.env)
    Config::merge(kernel defaults, site config/settings.php)
    ContainerBuilder           (PHP-DI, autowiring on)
    TwigFactory                (view paths: theme, app, kernel)
    AppFactory                 (Slim)
    middleware stack
    routes
```

Slim runs middleware last-added-first, so the effective order is:

```
SecurityHeaders → ErrorPage → Session → TemplateData → BodyParsing → CSRF → Routing → controller
```

| Middleware | Why it is there |
|---|---|
| SecurityHeaders | Wraps everything, including error responses: a 500 still gets CSP and `X-Frame-Options` |
| ErrorPage | Turns exceptions into friendly pages; sits inside the headers for that reason |
| Session | Starts the session before anything reads it |
| TemplateData | Resolves the locale and publishes Twig globals |
| BodyParsing | Before CSRF, so a form token reaches `parsedBody` |
| CSRF | Rejects state-changing requests without a valid token, unless the request authenticates with an API key |

### Security defaults

- The CSP is `'self'` only: no inline scripts, no `eval`, no third-party hosts.
  A site adds directives under `settings.security.csp`, which replaces the list;
  the baseline is written for a site that ships no frontend build step.
- `X-Forwarded-Proto` is ignored unless `REMOTE_ADDR` is listed in
  `TRUSTED_PROXIES`. On shared hosting with no proxy the list stays empty, so a
  forged header cannot claim HTTPS.

## Layers

| Layer | Responsibility | Must not |
|---|---|---|
| Controller | parse the request, validate input, call a service, render | contain SQL or business rules |
| Service | rules, orchestration, transactions, failure isolation | render HTML or read superglobals |
| Repository | SQL for one area, prepared statements | contain business rules |
| Support | small stateless helpers | reach the container |
| Middleware | cross-cutting request concerns | contain domain logic |

## Extension points

The kernel is designed to be extended, not modified:

| Need | Do this |
|---|---|
| A different user table | bind `Polaris\Auth\UserStore` |
| A handle, not just an email | bind `Polaris\Auth\HandleStore` |
| Extra rows for a new account | bind `Polaris\Auth\ProfileProvisioner` |
| Newsletter sync | bind `Polaris\Auth\SubscriberSync` and `NewsletterAudience` |
| Extra email types | extend `Polaris\Mail\Mailer` (the helpers are protected) |
| Extra Twig functions or filters | pass a `$decorateTwig` closure to `Kernel::boot` |
| Extra Twig globals | implement `Polaris\View\ViewGlobalsProvider` |
| Writable admin settings | declare `settings.writable_settings` rules |
| Registration rules | extend `Polaris\Auth\AuthService`, use `RegistrationValidator` |
| Image uploads | inject `Polaris\Services\ImageService`; bind `Polaris\Support\UploadCheck` for tests |
| A new REST/MCP operation | add an `Operation` to a group (`docs/API.md`) |

## Data

No ORM. `Polaris\Support\Database` is a thin PDO wrapper: prepared statements
with typed binds, `transaction()` with savepoints for nesting. Rows are plain
arrays — predictable, easy to serialise for the API, and no proxy objects to
debug at 2 a.m. Repositories own their SQL.

## Views and themes

Twig 3 with `autoescape: html`. The loader tries, in order:

1. `themes/<active>/views`
2. `resources/views` (the application)
3. `kernel/resources/views`

The first directory containing a template wins, so overriding the layout is
`cp kernel/resources/views/layout.twig themes/mine/views/layout.twig`. Themes
ship as files in the repository: there is no upload, no sandbox, and no
template-as-code risk. `asset()` resolves in the same order and fingerprints
with the file mtime, so a deploy always busts the browser cache.

## Internationalisation

`Translator` takes a list of language directories and a list of supported
locales. Lookup merges the kernel files and the application files (application
wins), then the database overrides (admin edits win over both).

For a request the locale is chosen by, highest priority first:

1. the URL prefix (`/es/faq`),
2. the account preference,
3. the session choice,
4. `Accept-Language`,
5. the default locale.

The URL wins on purpose: a shared Spanish link must render in Spanish even for a
visitor whose account is set to English. Getting this wrong is how a site ships
with a translated body and an untranslated navigation.

## Database migrations

Kernel SQL lives in `kernel/database/upgrades/`, application SQL in
`database/upgrades/`, both idempotent and applied in filename order. Applied
names are recorded in `_migrations`. `MigrationRunner` takes a MySQL advisory
lock so two deploys cannot race, and it does not wrap a file in a transaction:
MySQL commits DDL implicitly, so a transaction there would be a lie. A file that
fails halfway leaves no record, and re-running is safe because every file is
idempotent.

`database/sql/` is also accepted, for projects that started there before the
`upgrades/` convention existed.

## Deploy

```
bin/deploy.php
  test gate (lint + PHPUnit) → abort on failure
  scan the tree (no secrets, no tests, no runtime data)
  diff against var/deploy.json → changed files + delete list
  stage in deploy/files/
  upload over FTP, creating folders, resolving chrooted document roots
  apply SQL with a token-gated runner that deletes itself first
  advance the baseline only if everything succeeded
```

`bin/package.php` builds the archive a host needs: runtime `vendor/` only (it
stages with `composer install --no-dev` and refuses a tree that still contains
dev packages), a `MANIFEST.json` with a SHA-256 per file, and a `VERSION` file.

## Invariants

1. `kernel/` never imports `App\`.
2. Every query uses bound parameters.
3. Every state-changing route passes through CSRF, unless it lives under
   `/api/` (those routes must mount an API-key middleware) or carries a
   **valid** API key header (which a browser cannot send cross-origin). API
   routes are exempt by path so a missing or wrong key reaches the key
   middleware and answers 401 JSON instead of an HTML redirect.
4. Uploaded files never execute: `uploads/.htaccess` ships with every release
   and `ImageService` re-encodes images before storing them.
5. Every user-facing string goes through `Translator`.
6. Templates autoescape; `|raw` is explicit and reviewed.
7. A kernel upgrade never edits application files.
8. The version is single-sourced: `bin/version.php` keeps `VERSION`,
   `Kernel::VERSION` and the installer constant together, and the changelog has
   an entry.
