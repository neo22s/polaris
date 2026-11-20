<?php

declare(strict_types=1);

/*
 * Polaris one-file installer.
 *
 * Upload this file to the document root of an empty hosting, open it in a
 * browser, fill the short form and let it do the rest: download the release,
 * verify it, write .env, create the schema and create the first admin.
 *
 * Design notes, because they are the whole point:
 *
 * - It is a single file with no dependencies. It cannot assume Composer, a CLI
 *   or a terminal: the target is a cheap hosting with a file manager.
 * - It refuses to run when a site already exists. Re-running an installer over
 *   a live database is how sites get wiped.
 * - It removes itself when it finishes, and leaves a lock file behind. If the
 *   deletion fails (a host may forbid it), the lock still refuses a second run.
 * - The release URL is a constant, never a query parameter. A query parameter
 *   would turn this file into an open proxy that fetches any URL an attacker
 *   names.
 * - It verifies the archive before extracting: zip-slip entries, absolute
 *   paths, backslashes and an unexpected checksum all abort the install.
 */

const POLARIS_INSTALLER_VERSION = '0.2.4';

/*
 * Set these before uploading.
 *
 * POLARIS_RELEASE_URL must point at the release archive. For a GitHub release:
 *
 *   https://github.com/<owner>/<repo>/releases/latest/download/polaris-site.zip
 *
 * A private repository needs a token. Never commit a token to this file: use
 * an archive hosted somewhere the server can read anonymously, or pass a token
 * through the environment when you build the installer.
 */
const POLARIS_RELEASE_URL = 'https://github.com/neo22s/polaris/releases/latest/download/polaris-site.zip';

/* SHA-256 of the published archive. A mismatch aborts before extraction. */
const POLARIS_RELEASE_SHA256 = '9f261acae5363a8dd3fca4e40adda1f25823ba5d4ffdf44e49c6981a1c0f930c';

/* The file that marks a site as installed. */
const POLARIS_LOCK = 'var/installed';

$root = __DIR__;

/*
 * The installer has no autoloader, so it cannot use Clock. Keep the same SQL
 * timestamp format here: the rows it writes are read by the kernel.
 */
function polaris_now(int $at = 0): string
{
    return date('Y-m-d H:i:s', $at === 0 ? time() : $at);
}

/* Reads and deletes tolerate failure but record it: this file has no autoloader. */
function polaris_read(string $path): string|false
{
    $contents = @file_get_contents($path);

    if ($contents === false) {
        error_log('[Polaris] installer could not read ' . $path);
    }

    return $contents;
}

function polaris_delete(string $path): bool
{
    if (!file_exists($path)) {
        return true;
    }

    $deleted = @unlink($path);

    if ($deleted === false) {
        error_log('[Polaris] installer could not delete ' . $path);
    }

    return $deleted;
}

/**
 * Requirements, with two levels: blocking and recommended.
 *
 * @return array<string,bool>
 */
function polaris_requirements(): array
{
    return [
        'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'pdo_mysql (database driver)' => extension_loaded('pdo_mysql'),
        'mbstring (UTF-8 handling)' => extension_loaded('mbstring'),
        'json (API and manifests)' => extension_loaded('json'),
        'zip (release extraction)' => class_exists('ZipArchive'),
        'openssl (HTTPS downloads)' => extension_loaded('openssl'),
        'gd (image uploads, recommended)' => extension_loaded('gd'),
        'intl (localised dates, recommended)' => extension_loaded('intl'),
        'Document root writable' => is_writable(__DIR__),
        'var/ writable or creatable' => is_writable(__DIR__) || is_dir(__DIR__ . '/var'),
    ];
}

/**
 * The requirements that stop an install. The last two rows are recommendations.
 *
 * @param array<string,bool> $requirements
 * @return array<int,string>
 */
function polaris_required_failures(array $requirements): array
{
    $optional = ['gd (image uploads, recommended)', 'intl (localised dates, recommended)'];
    $failed = [];

    foreach ($requirements as $label => $ok) {
        if (!$ok && !in_array($label, $optional, true)) {
            $failed[] = $label;
        }
    }

    return $failed;
}

function polaris_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Fetch a URL with a timeout. Follows a few redirects, as GitHub releases do. */
function polaris_download(string $url): string
{
    // A local archive is a legitimate test path and does not need HTTP.
    if (str_starts_with($url, 'file://')) {
        $path = substr($url, 7);
        $body = polaris_read($path);

        if ($body === false) {
            throw new RuntimeException('Could not read the local release archive: ' . $path);
        }

        return $body;
    }

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Polaris-Installer/' . POLARIS_INSTALLER_VERSION,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($handle);
        curl_close($handle);

        if ($body === false || $status !== 200) {
            throw new RuntimeException(
                'Download failed (HTTP ' . $status . '). ' . $error
                . ' Check POLARIS_RELEASE_URL and that the release exists.',
            );
        }

        return (string) $body;
    }

    $body = polaris_read($url);

    if ($body === false) {
        throw new RuntimeException('Download failed. Enable the curl extension or check the URL.');
    }

    return $body;
}

/**
 * Extract an archive, refusing anything that could escape the root.
 *
 * @return array<int,string> The extracted relative paths.
 */
function polaris_extract(string $archive, string $root): array
{
    $zip = new ZipArchive();

    if ($zip->open($archive) !== true) {
        throw new RuntimeException('The downloaded file is not a readable zip archive.');
    }

    $extracted = [];

    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if ($name === '' || str_ends_with($name, '/')) {
                continue; // directory entry
            }

            // Zip-slip: no absolute paths, no parent traversal, no backslashes.
            if (str_contains($name, '\\')
                || str_starts_with($name, '/')
                || preg_match('#^[A-Za-z]:#', $name) === 1
                || in_array('..', explode('/', $name), true)
            ) {
                throw new RuntimeException('Refusing an unsafe path in the archive: ' . $name);
            }

            // The installer itself is never overwritten by the archive.
            if ($name === 'install.php') {
                continue;
            }

            $target = $root . '/' . $name;

            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                throw new RuntimeException('Could not create ' . dirname($target));
            }

            $contents = $zip->getFromIndex($i);

            if ($contents === false) {
                throw new RuntimeException('Could not read ' . $name . ' from the archive.');
            }

            file_put_contents($target, $contents);
            $extracted[] = $name;
        }
    } finally {
        $zip->close();
    }

    return $extracted;
}

/** @param array<string,string> $values */
function polaris_write_env(string $root, array $values): void
{
    $lines = [];

    foreach ($values as $key => $value) {
        // A newline in a value would let a form field inject a second key.
        $value = str_replace(["\r", "\n"], '', (string) $value);

        // Quote anything with a space, a # or a quote: dotenv parsers refuse
        // "APP_NAME=Test Site" (unquoted whitespace is a parse error), and a
        // half-written .env is the worst possible state to debug.
        if ($value === '' || preg_match('/[ \t#"\'$]/', $value) === 1) {
            $value = '"' . str_replace('"', '\"', $value) . '"';
        }

        $lines[] = $key . '=' . $value;
    }

    $content = "# Polaris environment\n# Generated by the installer. Keep this file private.\n\n"
        . implode("\n", $lines) . "\n";

    $apiKey = bin2hex(random_bytes(32));
    // Two independent surfaces, two keys: a REST key must not reach the tool
    // surface by accident, and rotating one must not break the other.
    $content .= "\n# Optional HTTP API (REST and MCP). Disabled while the keys are empty.\n"
        . "API_ENABLED=true\nAPI_KEY=" . $apiKey . "\n"
        . "MCP_ENABLED=true\nMCP_API_KEY=" . bin2hex(random_bytes(32)) . "\n";

    if (file_put_contents($root . '/.env', $content) === false) {
        throw new RuntimeException('Could not write .env in the document root.');
    }

    // Some hosts forbid chmod; the installer must still finish, and a wrong
    // permission is reported by the host rather than hiding a real failure.
    @chmod($root . '/.env', 0600);
}

/** @return array<string,string> The form values, trimmed. */
function polaris_input(): array
{
    $post = $_POST;

    return [
        'app_name' => trim((string) ($post['app_name'] ?? 'Polaris')),
        'app_url' => rtrim(trim((string) ($post['app_url'] ?? '')), '/'),
        'db_host' => trim((string) ($post['db_host'] ?? '127.0.0.1')),
        'db_port' => trim((string) ($post['db_port'] ?? '3306')),
        'db_name' => trim((string) ($post['db_name'] ?? '')),
        'db_user' => trim((string) ($post['db_user'] ?? '')),
        'db_pass' => (string) ($post['db_pass'] ?? ''),
        'admin_email' => strtolower(trim((string) ($post['admin_email'] ?? ''))),
        'mail_from' => trim((string) ($post['mail_from'] ?? '')),
        'mail_host' => trim((string) ($post['mail_host'] ?? '')),
    ];
}

/**
 * @param array<string,string> $input
 * @return array<int,string>
 */
function polaris_validate(array $input): array
{
    $errors = [];

    if ($input['app_name'] === '') {
        $errors[] = 'The site name is required.';
    }

    if (filter_var($input['app_url'], FILTER_VALIDATE_URL) === false) {
        $errors[] = 'The site URL must be a full URL, for example https://example.com.';
    }

    if ($input['db_name'] === '' || $input['db_user'] === '') {
        $errors[] = 'The database name and user are required.';
    }

    if (filter_var($input['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'The admin email must be a valid address.';
    }

    if ($input['db_port'] === '' || !ctype_digit($input['db_port'])) {
        $errors[] = 'The database port must be a number.';
    }

    return $errors;
}

/** @param array<string,string> $input */
function polaris_test_database(array $input): void
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $input['db_host'],
        (int) $input['db_port'],
        $input['db_name'],
    );

    $pdo = new PDO($dsn, $input['db_user'], $input['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $pdo->query('SELECT 1');
}

/**
 * Apply the kernel and application SQL.
 *
 * @return array<int,string>
 */
function polaris_migrate(string $root, array $dbConfig): array
{
    require_once $root . '/vendor/autoload.php';

    $db = new Polaris\Support\Database($dbConfig);

    // Kernel upgrades first, then the application's. MigrationRunner owns the
    // directory convention, so the installer cannot drift from the deploy.
    $directories = Polaris\Deploy\MigrationRunner::siteDirectories($root);

    if ($directories === []) {
        throw new RuntimeException('No SQL upgrades found. Is the release complete?');
    }

    return Polaris\Deploy\MigrationRunner::run($db, $directories);
}

/**
 * Create the first admin and a single-use login link for them.
 *
 * The installer does not ask for a password: Polaris is passwordless, and the
 * link is the same path a normal login takes. Printing it on screen means the
 * first admin can sign in even when SMTP is not configured yet.
 */
function polaris_create_admin(string $root, array $dbConfig, string $appUrl, string $email): string
{
    require_once $root . '/vendor/autoload.php';

    $db = new Polaris\Support\Database($dbConfig);

    $userId = $db->insert('users', [
        'email' => $email,
        'role' => 'admin',
        'status' => 'active',
        'created_at' => polaris_now(),
        'updated_at' => polaris_now(),
    ]);

    $token = bin2hex(random_bytes(32));

    $db->insert('magic_links', [
        'user_id' => $userId,
        'token_hash' => hash('sha256', $token),
        'expires_at' => polaris_now(time() + 900),
        'created_at' => polaris_now(),
    ]);

    return rtrim($appUrl, '/') . '/auth/' . $token;
}

function polaris_installed(string $root): bool
{
    return is_file($root . '/' . POLARIS_LOCK) || is_file($root . '/.env');
}

/**
 * Mark the site as installed, then try to remove the installer.
 *
 * Deletion is best effort: some hosts forbid it. The lock file is what
 * guarantees a second run cannot happen, and it is written first.
 */
function polaris_close_installer(string $root): bool
{
    if (!is_dir($root . '/var') && !mkdir($root . '/var', 0775, true) && !is_dir($root . '/var')) {
        return false;
    }

    file_put_contents($root . '/' . POLARIS_LOCK, json_encode([
        'installed_at' => date('c'),
        'installer' => POLARIS_INSTALLER_VERSION,
    ], JSON_PRETTY_PRINT));

    return polaris_delete(__FILE__);
}

$requirements = polaris_requirements();
$blocking = polaris_required_failures($requirements);
$installed = polaris_installed($root);
$input = polaris_input();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'install') {
    $errors = polaris_validate($input);

    if ($installed) {
        $errors[] = 'A site already exists in this document root. Remove .env and var/installed to reinstall.';
    }

    if ($blocking !== []) {
        $errors[] = 'Fix the failed requirements before installing.';
    }

    // https only, except a local file:// path used by the release smoke test.
    $isLocalArchive = str_starts_with(POLARIS_RELEASE_URL, 'file://');

    if (POLARIS_RELEASE_URL === ''
        || (!str_starts_with(POLARIS_RELEASE_URL, 'https://') && !$isLocalArchive)
    ) {
        $errors[] = 'POLARIS_RELEASE_URL must be an https URL to the release archive.';
    }

    if ($errors === []) {
        $temp = null;

        try {
            polaris_test_database($input);

            $temp = tempnam(sys_get_temp_dir(), 'polaris-release');

            if ($temp === false) {
                throw new RuntimeException('Could not create a temporary file for the download.');
            }

            $archive = polaris_download(POLARIS_RELEASE_URL);
            file_put_contents($temp, $archive);

            if (POLARIS_RELEASE_SHA256 !== '') {
                $actual = hash_file('sha256', $temp);

                if (!hash_equals(strtolower(POLARIS_RELEASE_SHA256), strtolower((string) $actual))) {
                    throw new RuntimeException('The release checksum does not match. Nothing was extracted.');
                }
            }

            $extracted = polaris_extract($temp, $root);

            if (!in_array('MANIFEST.json', $extracted, true)) {
                throw new RuntimeException('The archive has no MANIFEST.json. Is POLARIS_RELEASE_URL pointing at a site release?');
            }

            polaris_write_env($root, [
                'APP_NAME' => $input['app_name'],
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_URL' => $input['app_url'],
                'APP_TIMEZONE' => 'UTC',
                'LOCALES' => 'en,es',
                'ADMIN_EMAILS' => $input['admin_email'],
                'DB_HOST' => $input['db_host'],
                'DB_PORT' => $input['db_port'],
                'DB_DATABASE' => $input['db_name'],
                'DB_USERNAME' => $input['db_user'],
                'DB_PASSWORD' => $input['db_pass'],
                'DB_PERSISTENT' => 'false',
                'SESSION_NAME' => 'polaris_session',
                'SESSION_LIFETIME' => '31536000',
                'SESSION_REGENERATE_MINUTES' => '30',
                'MAIL_HOST' => $input['mail_host'] !== '' ? $input['mail_host'] : 'localhost',
                'MAIL_PORT' => '587',
                'MAIL_SECURE' => 'tls',
                'MAIL_AUTH' => $input['mail_host'] !== '' ? 'true' : 'false',
                'MAIL_FROM' => $input['mail_from'] !== '' ? $input['mail_from'] : 'no-reply@' . (parse_url($input['app_url'], PHP_URL_HOST) ?: 'localhost'),
                'MAIL_FROM_NAME' => $input['app_name'],
                'ACUMBAMAIL_ENABLED' => 'false',
                'CACHE_ENABLED' => 'true',
            ]);

            // The installer never reads the site's config/settings.php: it
            // knows the connection details it just wrote, and a site is free to
            // organise its config however it likes.
            $dbConfig = [
                'host' => $input['db_host'],
                'port' => (int) $input['db_port'],
                'database' => $input['db_name'],
                'username' => $input['db_user'],
                'password' => $input['db_pass'],
                'persistent' => false,
            ];

            $migrations = polaris_migrate($root, $dbConfig);
            $loginUrl = polaris_create_admin($root, $dbConfig, $input['app_url'], $input['admin_email']);

            // Create the runtime directories a fresh checkout does not ship.
            foreach (['var/cache/twig', 'var/sessions', 'var/logs', 'uploads'] as $directory) {
                if (!is_dir($root . '/' . $directory)) {
                    // A host that forbids mkdir is reported by the later write;
                    // the install itself can still complete.
                    @mkdir($root . '/' . $directory, 0775, true);
                }
            }

            $selfDestructed = polaris_close_installer($root);

            $result = [
                'migrations' => $migrations,
                'login_url' => $loginUrl,
                'self_destructed' => $selfDestructed,
            ];
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        } finally {
            if ($temp !== null && is_file($temp)) {
                polaris_delete($temp);
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Polaris installer</title>
<style>
    :root { --ink:#111; --muted:#666; --line:#e5e5e5; --ok:#0a7f3f; --bad:#b42318; --warn:#8a5a00; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: system-ui,-apple-system,"Segoe UI",Arial,sans-serif; color:var(--ink); background:#fafafa; }
    main { max-width: 46rem; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
    h1 { font-size: 1.6rem; margin: 0 0 .25rem; letter-spacing:-.01em; }
    .sub { color: var(--muted); margin: 0 0 2rem; }
    h2 { font-size: .95rem; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin: 2rem 0 .75rem; }
    .card { background:#fff; border:1px solid var(--line); border-radius:8px; padding:1.25rem; }
    table { width:100%; border-collapse: collapse; font-size:.92rem; }
    td { padding:.45rem 0; border-bottom:1px solid var(--line); }
    td:last-child { text-align:right; font-weight:600; }
    .ok { color: var(--ok); } .bad { color: var(--bad); } .warn { color: var(--warn); }
    label { display:block; font-size:.85rem; font-weight:600; margin:0 0 .3rem; }
    input { width:100%; padding:.6rem .7rem; font:inherit; border:1px solid var(--line); border-radius:6px; }
    .row { display:flex; gap:1rem; flex-wrap:wrap; }
    .row > .col { flex:1 1 12rem; }
    .field { margin: 0 0 1rem; }
    button { margin-top:1rem; padding:.8rem 1.4rem; font:inherit; font-weight:600; color:#fff; background:var(--ink); border:0; border-radius:6px; cursor:pointer; }
    ul.errors { margin:0 0 1.5rem; padding:.9rem 1rem .9rem 2rem; background:#fef3f2; border:1px solid #fecdca; border-radius:8px; color:var(--bad); }
    .notice { margin:0 0 1.5rem; padding:.9rem 1rem; background:#fffaeb; border:1px solid #fedf89; border-radius:8px; color:var(--warn); }
    code, .mono { font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.88rem; }
    .link-box { display:block; margin:.75rem 0 0; padding:.8rem .9rem; background:#f5f5f5; border:1px solid var(--line); border-radius:6px; word-break:break-all; }
    footer { margin-top:2.5rem; color:var(--muted); font-size:.8rem; }
</style>
</head>
<body>
<main>
<h1>Polaris installer</h1>
<p class="sub">Version <?= polaris_h(POLARIS_INSTALLER_VERSION) ?> · PHP <?= polaris_h(PHP_VERSION) ?></p>

<?php if ($installed): ?>
    <div class="notice">
        A site already exists in this document root (<code>.env</code> or <code>var/installed</code> is present).
        To reinstall, back up your database and remove both files. The installer will not touch an existing site.
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <ul class="errors">
        <?php foreach ($errors as $error): ?>
            <li><?= polaris_h($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($result !== null): ?>
    <div class="card">
        <h2 style="margin-top:0">Installation finished</h2>
        <p>Migrations applied: <strong><?= polaris_h((string) count($result['migrations'])) ?></strong></p>

        <p style="margin-top:1rem"><strong>Your admin login link</strong> (single use, 15 minutes):</p>
        <a class="link-box mono" href="<?= polaris_h($result['login_url']) ?>"><?= polaris_h($result['login_url']) ?></a>

        <p style="margin-top:1.25rem">
            <?php if ($result['self_destructed']): ?>
                <span class="ok">The installer deleted itself.</span>
            <?php else: ?>
                <span class="warn">The installer could not delete itself.</span>
                Delete <code>install.php</code> manually: the lock file already blocks a second run.
            <?php endif; ?>
        </p>

        <p class="sub" style="margin-top:1.25rem;margin-bottom:0">
            Next: configure SMTP in <code>.env</code> so login links are emailed, then sign in with the link above.
        </p>
    </div>
<?php else: ?>

<h2>1 · Requirements</h2>
<div class="card">
    <table>
        <?php foreach ($requirements as $label => $ok): ?>
            <?php
            $recommended = str_contains($label, 'recommended');
            $state = $ok ? 'ok' : ($recommended ? 'warn' : 'bad');
            $text = $ok ? 'OK' : ($recommended ? 'Recommended' : 'Missing');
            ?>
            <tr>
                <td><?= polaris_h($label) ?></td>
                <td class="<?= $state ?>"><?= $text ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if ($blocking === [] && !$installed): ?>
<h2>2 · The site</h2>
<form method="post" class="card" autocomplete="off">
    <input type="hidden" name="step" value="install">

    <div class="row">
        <div class="col field">
            <label for="app_name">Site name</label>
            <input id="app_name" name="app_name" value="<?= polaris_h($input['app_name']) ?>" required>
        </div>
        <div class="col field">
            <label for="app_url">Site URL</label>
            <input id="app_url" name="app_url" type="url" placeholder="https://example.com"
                   value="<?= polaris_h($input['app_url'] !== '' ? $input['app_url'] : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'example.com')) ?>" required>
        </div>
    </div>

    <h2 style="margin-top:.5rem">Database</h2>
    <div class="row">
        <div class="col field">
            <label for="db_host">Host</label>
            <input id="db_host" name="db_host" value="<?= polaris_h($input['db_host']) ?>" required>
        </div>
        <div class="col field" style="flex:0 0 8rem">
            <label for="db_port">Port</label>
            <input id="db_port" name="db_port" value="<?= polaris_h($input['db_port']) ?>" required>
        </div>
    </div>
    <div class="row">
        <div class="col field">
            <label for="db_name">Database</label>
            <input id="db_name" name="db_name" value="<?= polaris_h($input['db_name']) ?>" required>
        </div>
        <div class="col field">
            <label for="db_user">User</label>
            <input id="db_user" name="db_user" value="<?= polaris_h($input['db_user']) ?>" required>
        </div>
    </div>
    <div class="field">
        <label for="db_pass">Password</label>
        <input id="db_pass" name="db_pass" type="password" value="<?= polaris_h($input['db_pass']) ?>">
    </div>

    <h2>Email</h2>
    <p class="sub" style="margin-bottom:.75rem">
        Optional. Without SMTP the login link still works: it is shown on screen after installing.
    </p>
    <div class="row">
        <div class="col field">
            <label for="mail_host">SMTP host</label>
            <input id="mail_host" name="mail_host" placeholder="smtp.example.com" value="<?= polaris_h($input['mail_host']) ?>">
        </div>
        <div class="col field">
            <label for="mail_from">From address</label>
            <input id="mail_from" name="mail_from" type="email" placeholder="no-reply@example.com" value="<?= polaris_h($input['mail_from']) ?>">
        </div>
    </div>

    <h2>First admin</h2>
    <div class="field">
        <label for="admin_email">Email</label>
        <input id="admin_email" name="admin_email" type="email" value="<?= polaris_h($input['admin_email']) ?>" required>
    </div>

    <button type="submit">Install</button>
</form>
<?php endif; ?>

<?php endif; ?>

<footer>
    Polaris is free software under the MIT license.
    The installer downloads the release from <span class="mono"><?= polaris_h(POLARIS_RELEASE_URL) ?></span>.
</footer>
</main>
</body>
</html>
