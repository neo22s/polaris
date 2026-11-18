# Polaris

**A PHP starter kit for building websites with AI, on your own hosting.**

Secure by default, updatable without merging code, and small enough to run on a
5 € shared hosting plan. No Node, no Docker, no Composer on the server.

```bash
composer check   # lint + 149 tests
```

No database needed: 143 tests are pure, 6 need MySQL and skip with a clear
message. CI runs them for real, on PHP 8.1 through 8.4.

---

## Why this exists

Anyone can ask an AI for a website today. What they get is usually code with no
tests, no migrations, no CSRF protection, no documentation and no way to update
it — because nobody told the model those were requirements.

Polaris makes them requirements:

| | |
|---|---|
| **AI-native** | `AGENTS.md` is the contract an agent must follow. The scaffolder, the API layer and `composer check` make the correct shape the easy shape. |
| **Secure by default** | CSRF on every state-changing request, prepared statements everywhere, single-use hashed magic links, upload re-encoding, CSP and security headers. |
| **Updatable** | `kernel/` is read-only and replaced on upgrade. Your `app/` is never merged. |
| **Tested** | 149 kernel tests. A bug fix ships with the test that failed first. |
| **Self-contained** | 19 runtime packages, ~4.5 MB of vendor, file cache instead of Redis, MySQL only. |
| **Portable** | Zip + installer, or incremental FTP deploy. Works where SSH does not exist. |

## The two ideas that make it different

### 1. Kernel and site, never merged

```
kernel/    read-only, replaceable, domain-free     → Polaris\
app/       your product                            → App\
```

One `composer.json` maps both namespaces. There is **no class shadowing**: you
cannot silently override a kernel class by dropping a file with the same name.
To change behaviour you extend a documented extension point, override a config
value, or copy a template. That is why upgrading is "replace a directory" and not
"resolve 40 conflicts".

### 2. One operation, three surfaces

A REST endpoint, an MCP tool and an OpenAPI entry are the same thing with a
different transport, so Polaris describes each operation **once**:

```php
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

That declaration produces:

```
POST /api/v1/invoices          ← REST route
tools/call invoices.create     ← MCP tool
/api/v1/openapi.json           ← OpenAPI 3.1 entry
/api/v1/docs                   ← readable page
```

A test asserts both surfaces return the same payload. Written separately, they
drift, and the drift is invisible until a client sends an argument the tool no
longer accepts.

## What is in the box

| Area | What you get |
|---|---|
| HTTP | Slim 4, PSR-7/PSR-15 middleware, security headers, friendly error pages |
| Auth | Magic links (single use, hashed at rest, 15 min), long sessions, roles, impersonation |
| Data | `Database` (typed binds, savepoints), repositories, no ORM |
| Views | Twig 3 with autoescape and a theme → app → kernel cascade |
| i18n | Configurable locales, URL prefix wins, per-language names, RTL-ready |
| API | Registry → REST + MCP + OpenAPI + docs, with pagination and API keys |
| Mail | PHPMailer, quoted-printable, table layout that survives Outlook and Gmail |
| Ops | File cache, rate limiter, cron status, signed releases, incremental FTP deploy |
| Tooling | `bin/make.php crud`, `bin/package.php`, `bin/deploy.php`, `bin/migrate.php`, `bin/version.php` |

## Quick start

### On a hosting (no SSH needed)

1. Create a MySQL database and user in the control panel.
2. Upload `install.php` to the document root and open it.
3. Fill five fields. It downloads the release, verifies it, writes `.env`,
   applies the schema, creates the first admin and prints a single-use login
   link, then deletes itself.

Read [`docs/INSTALL.md`](docs/INSTALL.md) for the security details.

### Locally

```bash
git clone https://github.com/neo22s/polaris
cd polaris/starter
composer install
cp .env.example .env          # set DB_* and APP_URL
ln -s .. kernel               # the kernel is a directory in a release
php bin/migrate.php
php -S 127.0.0.1:8000 -t .
```

Open `/setup`, create the admin, use the printed link.

### Work on the kernel itself

```bash
composer install
cp .env.example .env
composer check
```

The kernel suite must pass with **no application present**. `KernelPurityTest`
fails if kernel code references `App\` or a product brand.

## Build a feature

```bash
php bin/make.php crud Product \
  --fields="name:string!,price:decimal!,stock:int,active:bool"
```

```
create    app/Repositories/ProductRepository.php
create    app/Api/ProductApi.php              ← 5 operations, REST + MCP
create    app/Controllers/Admin/ProductController.php
create    resources/views/admin/products/index.twig
create    resources/views/admin/products/form.twig
create    database/upgrades/…_create_products.sql
```

It prints the three things it cannot do for you: the routes to paste, the group
to register, and the translation keys to add. `--diff` shows without writing;
`--force` overwrites. It refuses to write inside `kernel/`.

## Build a site with an agent

Paste the prompt in [`docs/AI-PROMPT.md`](docs/AI-PROMPT.md) into Claude, ChatGPT,
OpenCode or any agent that can read your files. It makes the agent:

1. read `AGENTS.md` before writing anything,
2. **ask the questions that matter and wait** (hosting, domain, database,
   languages, payments, who manages orders),
3. show a plan — entities, screens, API operations, and what it will **not**
   build in v1 — for your approval,
4. build in order: entities → services → admin → public → API → tests → i18n,
5. run `composer check` after each block, and fix failures instead of disabling
   tests.

The agent cannot declare victory on code that does not lint or pass tests: the
command decides, not the model.

## Deploy

```bash
php bin/package.php --site=../my-site    # release zip: runtime vendor, no dev deps
php bin/deploy.php --upload              # test gate → package → FTP → SQL
```

`bin/deploy.php` runs the suite first and aborts on failure, uploads only changed
files, applies pending SQL through a token-gated script that deletes itself, and
**only advances the baseline when everything succeeded**. Credentials live in
`.deploy.env` in the site — never in the kernel.

See [`docs/DEPLOY.md`](docs/DEPLOY.md).

## Versioning

```bash
php bin/version.php              # 0.1.0
php bin/version.php bump minor   # writes VERSION and Kernel::VERSION together
```

SemVer. `CHANGELOG.md` must have an entry for the version or the release fails
its tests. A site has its own version, independent of the kernel, so an operator
can report "my site is 1.2.0 on kernel 0.3.1".

Policy: in `0.x` anything may break. **1.0 means "I will not break it"**, and it
will not be declared until a second real site runs on the kernel.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `mbstring`, `json`
- `gd` (uploads) and `intl` (localised dates) recommended
- MySQL 5.7+ / MariaDB 10.2+
- Apache with `mod_rewrite` (an nginx snippet is in the docs)

## Documentation

| Document | What it answers |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Layers, request lifecycle, extension points |
| [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md) | Coding standards and the kernel/app boundary |
| [`docs/API.md`](docs/API.md) | One operation → REST + MCP + docs |
| [`docs/INSTALL.md`](docs/INSTALL.md) | The one-file installer, end to end |
| [`docs/DEPLOY.md`](docs/DEPLOY.md) | Releases and incremental FTP deployment |
| [`docs/ADDING-A-LANGUAGE.md`](docs/ADDING-A-LANGUAGE.md) | Ship a language in three files and one line |
| [`docs/RECIPES.md`](docs/RECIPES.md) | Add a page, an email, a theme, a setting |
| [`docs/UPGRADING.md`](docs/UPGRADING.md) | How a site moves to a newer kernel |
| [`docs/AI-PROMPT.md`](docs/AI-PROMPT.md) | The prompt that starts a project |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | What is done and what is next |
| [`AGENTS.md`](AGENTS.md) | The contract for an AI agent working in a site |

## Repository layout

```
kernel (this repository)
  src/            Polaris\  Support, Auth, Api, Mail, View, Middleware, Deploy, Cli
  config/         kernel defaults
  resources/      base layout, error pages, en/es
  database/       core SQL upgrades
  bin/            lint, migrate, package, deploy, version, kernel, make
  docs/           the reference
  starter/        a complete site: auth, admin, API, /setup, AGENTS.md, AI prompt
  install.php     the one-file installer
```

`starter/` is a real site, not a sample: it installs, it runs, and it is what
`php bin/package.php --site=starter` ships.

## Testing

```bash
composer test     # PHPUnit
composer check    # PHP syntax lint + PHPUnit
```

143 tests run with no services at all: pure helpers, URL safety, pagination,
version consistency, MIME output, archive contents.

6 tests talk to MySQL (the migration runner and the rate limiter). Without a
database they skip with a message, so a fresh clone is green out of the box.
With `TEST_DB_*` set they run for real.

CI sets `POLARIS_REQUIRE_INTEGRATION=1`, which turns that skip into a failure:
a database that is down must not look like a passing suite.

```bash
mysql -uroot -e "CREATE DATABASE polaris_test"
TEST_DB_DATABASE=polaris_test composer test:integration
```

- Fakes for email and uploads: no test reaches a real server.
- Guards that fail loudly: kernel purity (no `App\` references), version drift,
  mail MIME correctness, API/REST payload parity, deploy archive contents.

## Security

- Passwordless: magic links are hashed at rest, single use, 15 minutes.
- CSRF on every state-changing request; API requests authenticate by header and
  are exempt because a browser cannot send that header cross-origin.
- All SQL uses prepared statements with typed binds.
- Uploads are validated by real content and re-encoded.
- Redirects from request data go through `Url::safePath()`.
- Production runs with `APP_DEBUG=false`; error pages never leak traces.
- Reporting: see [`SECURITY.md`](SECURITY.md).

## License

MIT — see [`LICENSE`](LICENSE). The name and logo are not covered by the license;
see [`TRADEMARK.md`](TRADEMARK.md).

Polaris is **not affiliated** with `polaris/core` (by 2am.tech), which declares
the same `Polaris\` PHP namespace. Do not install both in one project: Composer
cannot map `Polaris\` to two directories.
