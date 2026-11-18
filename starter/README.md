# A Polaris site

Built with [Polaris](https://github.com/neo22s/polaris), a starter kit for
shared hosting: Slim 4, Twig 3, PDO/MySQL, PHPUnit. No ORM, no Node build step,
no Composer on the server.

## First run

1. Create a database and a user on the host.
2. Upload the release (with `vendor/`) to the document root.
3. Copy `.env.example` to `.env` and fill in `APP_URL` and the database.
4. Open `/setup` and create the first admin. It prints a login link and then
   disappears.
5. Configure SMTP in `.env` so future login links are emailed.

## Work on it

```bash
composer install
composer check        # lint + tests
```

## Build something

```bash
php bin/make.php crud Product --fields="name:string!,price:decimal!,stock:int,active:bool"
```

That writes a repository, an API group, an admin controller, two templates and a
migration. Then paste the routes it prints and register the group. The API group
gives you REST **and** MCP tools from the same definition.

## Deploy

```bash
php kernel/bin/deploy.php --upload     # test gate, incremental FTP, SQL
```

Credentials live in `.deploy.env`, never in the kernel.

## Where things are

```
app/Controllers   HTTP
app/Api           operations: REST + MCP + docs from one definition
app/Repositories  SQL
resources/views   Twig
resources/lang    en, es
database/upgrades idempotent SQL
kernel/           the framework: read-only, replaced on upgrade
AGENTS.md         the contract an AI agent must follow here
docs/AI-PROMPT.md the prompt that starts a project
```
