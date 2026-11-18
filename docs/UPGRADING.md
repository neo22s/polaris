# Upgrading a site to a newer kernel

The promise: **a kernel upgrade replaces a directory and never touches your
application code.**

## What an upgrade changes

| Path | Touched? |
|---|---|
| `kernel/` | Replaced entirely |
| `kernel/database/upgrades/*.sql` | Applied, in filename order |
| `app/`, `config/`, `resources/`, `database/upgrades/` | **Never** |
| `vendor/` | Only if the kernel's dependencies changed |

## Manual upgrade

```bash
# 1. Read the release notes for the range you are jumping over.
# 2. Back up the database and the current kernel.
mysqldump -u USER -p DB > backup.sql
tar czf kernel-before.tar.gz kernel/

# 3. Replace the kernel directory with the new version.
rm -rf kernel && cp -r /path/to/polaris-1.4.2/kernel kernel/

# 4. Clear compiled templates, then check the site.
rm -rf var/cache/twig
```

## With the admin updater

The updater does the same thing, plus verification and rollback:

1. Download the release archive over HTTPS.
2. Verify the manifest signature and the file checksums.
3. Check `kernel/VERSION` against the version this site declares.
4. Back up the database (native dump if available, PHP dump otherwise).
5. Enable maintenance mode.
6. Replace `kernel/`.
7. Apply pending `kernel:` migrations.
8. Clear the cache.
9. Health check: home page, login page, an authenticated route.
10. On any failure: restore the kernel snapshot and the database backup, then
    leave maintenance mode.

The updater never writes outside `kernel/` and `var/`, except for the database
migrations it records in `_migrations`.

## Where the version lives

Two files, one command:

```bash
php bin/version.php            # show
php bin/version.php 0.2.0      # set VERSION and Kernel::VERSION together
php bin/version.php bump patch # or minor / major
```

- `VERSION` — read by the packager and the installer.
- `Kernel::VERSION` — read at runtime, so the admin panel reports it.
- `CHANGELOG.md` — must have an entry for the version, or the release fails its
  tests. That is deliberate: a release nobody can audit is a release nobody
  should deploy.

A site has its own `VERSION`, separate from the kernel's, so an operator can say
"my site is 1.2.0 on kernel 0.3.1" when reporting a bug.

## Version policy

- Kernel versions follow SemVer.
- A site declares the range it accepts in `config/settings.php`:

```php
'kernel_version' => '^1.2',
```

- Patch releases contain fixes only. Minor releases may add API, never remove
  it. Major releases may break, and ship an `UPGRADING-1.x-to-2.0.md`.
- After 1.0 the kernel is frozen for features: new functionality ships as
  separate packages, so "upgrade" stays a directory swap.

## If something breaks

1. `kernel/VERSION` tells you which version is running.
2. `var/logs/` has the PHP error log; the admin panel shows the last deploy.
3. Restore `kernel-before.tar.gz` and the SQL backup. The application code is
   untouched, so the site comes back exactly as it was.
4. Open an issue with the kernel version, the PHP version and the trace.

## Writing a kernel change that is safe to upgrade

- Never rename or remove a public method in a minor release; deprecate first.
- Database changes must be additive and idempotent.
- A new required PHP extension needs a `composer.json` change **and** a note in
  the release notes, because shared hostings apply extensions manually.
- New dependencies need a paragraph explaining why the existing ones cannot do
  the job; the dependency count is part of the product.
