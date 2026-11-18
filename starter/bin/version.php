<?php

declare(strict_types=1);

/*
 * Set this site's version, in VERSION and in config/settings.php.
 *
 * The site version is separate from the kernel version: an operator needs to
 * know both ("my site is 1.2.0 on kernel 0.3.1") when reporting a bug.
 *
 * Usage:
 *   php bin/version.php                 show it
 *   php bin/version.php 1.2.0           set it
 *   php bin/version.php bump patch      patch | minor | major
 */

$root = dirname(__DIR__);
$file = $root . '/VERSION';
$semver = '/^\d+\.\d+\.\d+$/';

$current = trim((string) @file_get_contents($file));
$args = array_slice($argv ?? [], 1);

if ($args === []) {
    fwrite(STDOUT, ($current === '' ? 'unknown' : $current) . "\n");
    exit(0);
}

$requested = $args[0];

if ($requested === 'bump') {
    if (preg_match($semver, $current) !== 1) {
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
    fwrite(STDERR, "Not a SemVer version: {$requested}\n");
    exit(1);
}

file_put_contents($file, $requested . "\n");
fwrite(STDOUT, "Site version set to {$requested}.\n");
