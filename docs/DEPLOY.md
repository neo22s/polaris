# Deploying

Two paths, same result: a site running on a host that only offers FTP and a
file manager.

## Before the first deploy

1. Create the MySQL database and user on the host.
2. Upload a full release (see "Release" below) once, or run the installer.
3. Create `.deploy.env` in the site from `.deploy.env.example` and fill in the
   FTP host, user, password, root and `SITE_URL`.

`.deploy.env` is git-ignored and never packaged. The kernel ships no
credentials: a release archive that contains an FTP password is a breach
waiting for a mirror.

## Release

A release is a zip the host can use as-is: runtime `vendor/` included, tests and
development packages excluded.

```bash
# From the kernel repository, for a site that already has its own app/:
php bin/package.php --site=/path/to/my-site

# Or the kernel alone, as a Composer-style package:
php bin/package.php
```

The site release is staged in a temporary directory and built with
`composer install --no-dev` there, so the developer's `vendor/` (PHPUnit and
friends) never reaches the archive. The script refuses to package a tree that
still contains dev packages.

Output:

```
dist/polaris-site-1.4.2.zip
  files: 3981
  size: 4.7 MB
  sha256: e1e725…
```

Put that file somewhere reachable and point `POLARIS_RELEASE_URL` in
`install.php` at it. On GitHub, upload it as a release asset and use
`releases/latest/download/polaris-site.zip`.

## Incremental FTP deploy

```bash
php bin/deploy.php --init        # first time: record the current tree
php bin/deploy.php --upload      # package changes and push them
php bin/deploy.php --full --upload   # resync everything
```

What happens, in order:

1. **Test gate** — `bin/lint.php` and the PHPUnit suite. A failure aborts the
   deploy. `--skip-tests` exists and is a bad idea.
2. **Scan** the tree, skipping `.git`, `var/`, `uploads/`, `tests/`, `dist/`,
   dotfiles (except `.htaccess`) and logs.
3. **Diff** against `var/deploy.json` — only changed files go in the package,
   with a delete list for what disappeared.
4. **Upload** over FTP, creating folders and resolving the document root on
   chrooted accounts.
5. **Migrate** with a one-off script: uploaded, called over HTTPS with
   `DEPLOY_TOKEN`, and deleted before it does anything else.
6. **Advance the baseline** only if every file uploaded and the SQL applied.
   A failed deploy can be re-run without re-uploading the world.

Nothing in this flow touches `kernel/` differently from `app/`: an upgrade is
files, and the SQL is idempotent.

## What the deploy never does

- Never uploads `.env`, `.deploy.env`, `var/`, `uploads/` or logs.
- Never writes the baseline before the upload succeeded.
- Never runs SQL without the token.
- Never leaves the migration runner behind: it removes itself first, and the
  deploy removes it again from the client side.

## After a deploy

Check, at minimum:

- the home page;
- the login page;
- one authenticated route;
- the admin cron/status panel when the deployment touched the cron.

If the site returns 500 on every page, the usual cause is a stale compiled
template or a missing `vendor/`: clear `var/cache/` and re-run
`php bin/deploy.php --full --upload`.
