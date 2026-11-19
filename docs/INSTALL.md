# Installing

Polaris installs from a single PHP file, on a host that offers a file manager,
FTP and MySQL. No SSH, no Composer, no terminal.

## What you need

- PHP 8.1+ with `pdo_mysql`, `mbstring`, `json`, `zip` and `openssl`
- MySQL 5.7+ or MariaDB 10.2+
- An empty document root
- A database and a database user already created (most hosts give you this in
  the control panel)

`gd` (image uploads) and `intl` (localised dates) are recommended but optional:
the installer reports them and continues without them.

## Steps

1. **Build a release** (see `docs/DEPLOY.md`) and publish it. On GitHub, upload
   `dist/polaris-site-<version>.zip` as a release asset.

2. **Set the release URL** at the top of `install.php`:

   ```php
   const POLARIS_RELEASE_URL = 'https://github.com/you/polaris/releases/latest/download/polaris-site.zip';
   ```

   Optionally set `POLARIS_RELEASE_SHA256` to the `sha256` printed by
   `bin/package.php`. With it set, a corrupted or tampered download is rejected
   before anything is written.

3. **Upload `install.php`** to the document root and open it in a browser.

4. **Fill the form**: site name, URL, database, email. The installer then:
   - checks the requirements;
   - connects to the database (before downloading anything);
   - downloads the release over HTTPS and verifies the checksum;
   - extracts it, rejecting unsafe paths;
   - writes `.env` with the credentials and a generated API key;
   - applies the kernel and application SQL;
   - creates the first admin and prints a single-use login link;
   - deletes itself and writes `var/installed`.

5. **Sign in** with the printed link, then configure SMTP in `.env` so future
   login links are emailed.

## Why the login link is printed

Polaris is passwordless: there is no password to choose during installation.
Asking the admin to wait for an email would fail on every host where SMTP is not
configured yet — which is all of them, at install time. The link is the same
mechanism a normal login uses; it just gets handed over on screen.

The link is single use and expires in 15 minutes. If it expires before you use
it, request a new one at `/login` once SMTP works, or insert a row manually:

```sql
INSERT INTO users (email, role, status, created_at, updated_at)
VALUES ('you@example.com', 'admin', 'active', NOW(), NOW());
```

## Re-running the installer

The installer refuses to run when `.env` or `var/installed` exists. To reinstall,
back up the database and delete both files. This is deliberate: an installer that
runs twice over a live database is a data-loss bug.

If the host forbids deleting `install.php`, the lock file still blocks a second
run — delete the file by hand when you can.

## Security notes

- The release URL is a constant, not a query parameter. A parameter would turn
  the installer into an open proxy that fetches any URL an attacker names.
- The archive is checked for absolute paths, `..`, backslashes and symlinks
  before extraction.
- `.env` is written with `0600`: on shared hosting the group id is often shared
  with other customers, so group-readable credentials are not acceptable.
- The installer never reads the site's `config/settings.php`: it uses the
  connection details it just wrote and nothing else.
- The installer deletes itself after a successful run; `var/installed` blocks a
  second run even when the host forbids that deletion.

## After installing

- Set `APP_DEBUG=false` (the installer already does).
- Point the cron at `php bin/cron.php` once a day: it prunes the cache and runs
  any tasks registered in `config/cron.php` (see `docs/RECIPES.md`).
- Create `.deploy.env` if you want to deploy over FTP from then on.
- Add the languages you ship in `.env` (`LOCALES=en,es`) and read
  `docs/ADDING-A-LANGUAGE.md`.
