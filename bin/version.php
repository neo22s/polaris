<?php

declare(strict_types=1);

/*
 * The single command that changes the version.
 *
 * The number lives in `VERSION` (read by the packager and the installer) and in
 * `Kernel::VERSION` (read at runtime, because a constant cannot come from a
 * file). Two places is one too many to edit by hand: a release where they
 * disagree ships an admin panel that lies about what is running.
 *
 * Usage:
 *   php bin/version.php                 show the current version
 *   php bin/version.php 0.2.0           set it
 *   php bin/version.php bump patch      patch | minor | major
 */

$root = dirname(__DIR__);
$file = $root . '/VERSION';

$current = trim((string) @file_get_contents($file));

$args = array_slice($argv ?? [], 1);

$semver = '/^\d+\.\d+\.\d+$/';

if ($args === []) {
    fwrite(STDOUT, ($current === '' ? 'unknown' : $current) . "\n");
    exit(0);
}

$requested = $args[0];

if ($requested === 'bump') {
    if (!preg_match($semver, $current, $match) === 1) {
        fwrite(STDERR, "Current VERSION is not SemVer: {$current}\n");
        exit(1);
    }

    [$major, $minor, $patch] = array_map('intval', explode('.', $current));

    $requested = match ($args[1] ?? '') {
        'major' => ($major + 1) . '.0.0',
        'minor' => $major . '.' . ($minor + 1) . '.0',
        'patch' => $major . '.' . $minor . '.' . ($patch + 1),
        default => '',
    };

    if ($requested === '') {
        fwrite(STDERR, "Usage: php bin/version.php bump patch|minor|major\n");
        exit(1);
    }
}

if (preg_match($semver, $requested) !== 1) {
    fwrite(STDERR, "Not a SemVer version (expected MAJOR.MINOR.PATCH): {$requested}\n");
    exit(1);
}

file_put_contents($file, $requested . "\n");

// Keep the runtime constant in step. A failure here is fatal on purpose: a
// release with two versions is worse than no release.
$kernel = $root . '/src/Http/Kernel.php';
$source = (string) file_get_contents($kernel);
$updated = preg_replace(
    "/public const VERSION = '[^']*';/",
    "public const VERSION = '{$requested}';",
    $source,
    1,
    $count,
);

if ($count !== 1 || $updated === null) {
    fwrite(STDERR, "Could not update Kernel::VERSION. Edit src/Http/Kernel.php by hand.\n");
    exit(1);
}

file_put_contents($kernel, $updated);

fwrite(STDOUT, "Version set to {$requested}.\n");
fwrite(STDOUT, "Now add the CHANGELOG entry before releasing:\n\n  ## [{$requested}] - " . date('Y-m-d') . "\n");
