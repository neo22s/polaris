<?php

declare(strict_types=1);

/*
 * Deploy a site over FTP.
 *
 * Usage (from the site root):
 *   php bin/deploy.php --init              Mark the current tree as deployed.
 *   php bin/deploy.php                     Build the package of changes.
 *   php bin/deploy.php --full              Package everything (first deploy).
 *   php bin/deploy.php --zip               Also produce deploy-<date>.zip.
 *   php bin/deploy.php --upload            Build, then upload over FTP.
 *   php bin/deploy.php --full --upload     First full deploy.
 *   php bin/deploy.php --upload-only       Upload the existing deploy/ folder.
 *   php bin/deploy.php --skip-tests        Skip the test gate (not recommended).
 *
 * Credentials live in .deploy.env in the site, never in the kernel: a release
 * archive must not contain an FTP password. See .deploy.env.example.
 *
 * The test suite runs first and aborts the deploy on failure. Files are only
 * uploaded when they differ from the baseline, the SQL is applied by a
 * one-off script that deletes itself, and the baseline only advances when
 * everything succeeded — so a failed deploy can be re-run safely.
 */

use Polaris\Deploy\DeployConfig;
use Polaris\Deploy\FtpUploader;
use Polaris\Deploy\MigrationRunner;
use Polaris\Deploy\RemoteRunner;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$args = $argv ?? [];
$init = in_array('--init', $args, true);
$full = in_array('--full', $args, true);
$zip = in_array('--zip', $args, true);
$upload = in_array('--upload', $args, true) || in_array('--upload-only', $args, true);
$uploadOnly = in_array('--upload-only', $args, true);
$skipTests = in_array('--skip-tests', $args, true);

$stateFile = $root . '/var/deploy.json';
$packageDir = $root . '/deploy';

// ---------------------------------------------------------------------------
// Test gate
// ---------------------------------------------------------------------------
if (!$skipTests && !$uploadOnly) {
    fwrite(STDOUT, "Running PHP lint…\n");
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/kernel/bin/lint.php'), $lintExit);

    if ($lintExit !== 0) {
        fwrite(STDERR, "PHP lint failed. Deploy aborted (use --skip-tests to override).\n");
        exit(1);
    }

    fwrite(STDOUT, "Running the test suite…\n");
    putenv('POLARIS_REQUIRE_INTEGRATION=1');

    $phpunit = is_file($root . '/vendor/bin/phpunit') ? $root . '/vendor/bin/phpunit' : null;

    if ($phpunit !== null) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit), $exitCode);
    } else {
        fwrite(STDERR, "No PHPUnit binary found. Deploy aborted (use --skip-tests to override).\n");
        $exitCode = 1;
    }

    putenv('POLARIS_REQUIRE_INTEGRATION');

    if (($exitCode ?? 0) !== 0) {
        fwrite(STDERR, "Tests failed. Deploy aborted (use --skip-tests to override).\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Scan the tree
// ---------------------------------------------------------------------------
/**
 * Paths never packaged: local-only files, secrets and user content.
 *
 * @return array<int,string>
 */
$excludes = ['.git', '.github', 'node_modules', 'var', 'uploads', 'deploy', 'tests', 'dist'];

/** @return array<string,string> relative path => sha1 */
function polaris_scan(string $root, array $excludes): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if (!polaris_included($relative, $excludes)) {
            continue;
        }

        $files[$relative] = (string) sha1_file($file->getPathname());
    }

    ksort($files);

    return $files;
}

/** @param array<int,string> $excludes */
function polaris_included(string $relative, array $excludes): bool
{
    // uploads/ holds user content and is never packaged, but its .htaccess
    // disables script execution and must always be deployed.
    if ($relative === 'uploads/.htaccess') {
        return true;
    }

    foreach (explode('/', $relative) as $part) {
        if (in_array($part, $excludes, true)) {
            return false;
        }
    }

    $base = basename($relative);

    // Dotfiles are local configuration, except the rewrite rules a host needs.
    if (str_starts_with($base, '.') && !in_array($base, ['.htaccess', '.env.example'], true)) {
        return false;
    }

    return !str_ends_with($base, '.log');
}

function polaris_delete_dir(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($directory);
}

// ---------------------------------------------------------------------------
// Build the package
// ---------------------------------------------------------------------------
$newState = null;
$changed = [];
$deleted = [];
$migrations = [];

if (!$uploadOnly) {
    $current = polaris_scan($root, $excludes);

    if ($init) {
        if (!is_dir(dirname($stateFile))) {
            @mkdir(dirname($stateFile), 0775, true);
        }

        file_put_contents($stateFile, json_encode(['generated_at' => date('c'), 'files' => $current], JSON_PRETTY_PRINT));
        fwrite(STDOUT, 'Baseline saved (' . count($current) . " files). Future runs package only the changes.\n");
        exit(0);
    }

    if (!is_file($stateFile) && !$full) {
        fwrite(STDERR, "No deploy baseline. Use --full for the first deploy, or --init.\n");
        exit(1);
    }

    if ($full) {
        $changed = array_keys($current);
        $deleted = [];
    } else {
        $state = json_decode((string) file_get_contents($stateFile), true) ?: [];
        $previous = $state['files'] ?? [];

        foreach ($current as $relative => $hash) {
            if (!isset($previous[$relative]) || $previous[$relative] !== $hash) {
                $changed[] = $relative;
            }
        }

        $deleted = array_values(array_diff(array_keys($previous), array_keys($current)));
    }

    // SQL is applied by the runner, never uploaded as a file.
    $migrations = RemoteRunner::collect(MigrationRunner::siteDirectories($root));
    $changed = array_values(array_filter(
        $changed,
        static fn (string $relative): bool => !str_ends_with($relative, '.sql') || !str_contains($relative, '/database/'),
    ));

    polaris_delete_dir($packageDir);

    foreach ($changed as $relative) {
        $destination = $packageDir . '/files/' . $relative;

        if (!is_dir(dirname($destination))) {
            @mkdir(dirname($destination), 0775, true);
        }

        copy($root . '/' . $relative, $destination);
    }

    file_put_contents($packageDir . '/DELETE.txt', $deleted === [] ? '' : implode("\n", $deleted) . "\n");
    file_put_contents(
        $packageDir . '/MANIFEST.txt',
        "Polaris deploy package\n"
        . 'Generated: ' . date('c') . "\n\n"
        . 'Changed files: ' . count($changed) . "\n"
        . 'Deleted files: ' . count($deleted) . "\n"
        . 'SQL migrations: ' . count($migrations) . "\n",
    );

    // Only persisted after a successful upload: a build-only run must not make
    // a later upload believe there is nothing to send.
    $newState = json_encode(['generated_at' => date('c'), 'files' => $current], JSON_PRETTY_PRINT);

    fwrite(STDOUT, sprintf(
        "Package ready in deploy/\n  changed: %d\n  deleted: %d\n  migrations: %d\n",
        count($changed),
        count($deleted),
        count($migrations),
    ));

    if ($zip && class_exists('ZipArchive')) {
        $zipPath = $root . '/deploy-' . date('Ymd-His') . '.zip';
        $archive = new ZipArchive();
        $archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $archive->addFile($file->getPathname(), substr($file->getPathname(), strlen($packageDir) + 1));
            }
        }

        $archive->close();
        fwrite(STDOUT, 'Zip: ' . basename($zipPath) . "\n");
    }
}

if (!$upload) {
    fwrite(STDOUT, "\nUpload deploy/files/ manually, or run with --upload.\n");
    exit(0);
}

// ---------------------------------------------------------------------------
// Upload
// ---------------------------------------------------------------------------
try {
    $config = DeployConfig::fromFile($root . '/.deploy.env');
    $ftp = new FtpUploader($config->ftp());
    $ftp->connect();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, 'Remote root: ' . $ftp->remoteRoot() . "\n");

// Collect the package from disk, so --upload-only works on a package built by
// an earlier run.
$localRoot = $packageDir . '/files';
$files = [];

if (is_dir($localRoot)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($localRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $files[str_replace('\\', '/', substr($file->getPathname(), strlen($localRoot) + 1))] = $file->getPathname();
        }
    }
}

$uploaded = 0;
$failed = [];

foreach (array_chunk($files, 50, true) as $chunk) {
    $result = $ftp->upload($chunk);
    $uploaded += $result['uploaded'];
    $failed = array_merge($failed, $result['failed']);

    foreach ($result['failed'] as $relative) {
        fwrite(STDERR, "  FAILED {$relative}\n");
    }
}

$deleteList = [];
$deleteFile = $packageDir . '/DELETE.txt';

if (is_file($deleteFile)) {
    foreach (file($deleteFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $relative) {
        $relative = trim($relative);

        if ($relative !== '') {
            $deleteList[] = $relative;
        }
    }
}

$deleteFailed = $ftp->delete($deleteList);

foreach ($deleteFailed as $relative) {
    fwrite(STDERR, "  could not delete {$relative}\n");
}

if ($migrations === []) {
    $migrations = RemoteRunner::collect(MigrationRunner::siteDirectories($root));
}

$token = $config->get('DEPLOY_TOKEN', '');
$siteUrl = $config->get('SITE_URL', '');
$migrationOk = true;
$migrationMessage = 'No migrations.';

if ($migrations !== []) {
    if ($token === null || $siteUrl === null) {
        $migrationOk = false;
        $migrationMessage = 'Set SITE_URL and DEPLOY_TOKEN in .deploy.env to run migrations automatically.';
        fwrite(STDERR, '✗ ' . $migrationMessage . "\n");
    } else {
        $result = $ftp->runMigrations($migrations, $token, $siteUrl);
        $migrationOk = $result['ok'];
        $migrationMessage = $result['message'];
        fwrite(STDOUT, ($migrationOk ? '✔ ' : '✗ ') . $migrationMessage . "\n");
    }
}

$ftp->disconnect();

fwrite(STDOUT, sprintf(
    "\nUploaded %d file(s), %d failed, %d deleted.\n",
    $uploaded,
    count($failed),
    count($deleteList) - count($deleteFailed),
));

if ($newState !== null && $failed === [] && $migrationOk) {
    file_put_contents($stateFile, $newState);
    fwrite(STDOUT, "Baseline updated.\n");
} elseif ($uploadOnly) {
    fwrite(STDOUT, "Baseline not advanced (--upload-only). Run a normal deploy when the tree matches.\n");
} else {
    fwrite(STDERR, "Baseline NOT updated: fix the problem and re-run the deploy.\n");
    exit(1);
}
