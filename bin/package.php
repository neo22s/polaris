<?php

declare(strict_types=1);

/*
 * Build a release archive.
 *
 * Usage:
 *   php bin/package.php                          kernel release, in dist/
 *   php bin/package.php --site=/path/to/site     full site release (vendor without dev packages)
 *   php bin/package.php --out=/path/to/dir       output directory
 *   php bin/package.php --keep-staging           leave the staged tree for inspection
 *
 * A site release is what the one-file installer downloads: everything needed to
 * run on a host without Composer, tests or git.
 */

use Polaris\Deploy\Packager;
use Polaris\Deploy\SiteBuilder;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$args = $argv ?? [];
$site = null;
$out = $root . '/dist';
$keepStaging = false;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--site=')) {
        $site = substr($arg, 7);
    } elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    } elseif ($arg === '--keep-staging') {
        $keepStaging = true;
    }
}

if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) {
    fwrite(STDERR, "Could not create {$out}\n");
    exit(1);
}

$version = trim((string) @file_get_contents($root . '/VERSION'));

if ($version === '') {
    fwrite(STDERR, "Missing VERSION. Run php bin/version.php <version>.\n");
    exit(1);
}

// A release with no changelog entry cannot be audited later, and the version
// test already enforces it for the kernel. Re-checking here keeps a site
// release honest too.
$changelog = $root . '/CHANGELOG.md';
if (is_file($changelog) && !str_contains((string) file_get_contents($changelog), '## [' . $version . ']')) {
    fwrite(STDERR, "CHANGELOG.md has no entry for {$version}. Add it before packaging.\n");
    exit(1);
}

if ($site !== null) {
    $siteRoot = realpath($site);

    if ($siteRoot === false || !is_dir($siteRoot)) {
        fwrite(STDERR, "Not a site directory: {$site}\n");
        exit(1);
    }

    fwrite(STDOUT, "Staging {$siteRoot} without development dependencies…\n");

    // When packaging `starter/` from this repository there is no `kernel/`
    // symlink (it is git-ignored), so the kernel has to be named explicitly.
    $kernelRoot = is_dir($siteRoot . '/kernel') ? null : $root;

    try {
        $staging = SiteBuilder::stage($siteRoot, 'composer', $kernelRoot);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    $dev = SiteBuilder::developmentPackages($staging);

    if ($dev !== []) {
        fwrite(STDERR, 'The staged tree still contains dev packages: ' . implode(', ', $dev) . "\n");
        SiteBuilder::removeDirectory($staging);
        exit(1);
    }

    // Write the version into the staged tree so the kernel and the manifest
    // agree even when the site carries a different one.
    file_put_contents($staging . '/VERSION', $version . "\n");

    $archive = $out . '/polaris-site-' . $version . '.zip';

    try {
        $result = Packager::build($staging, $archive, $version);
    } finally {
        if (!$keepStaging) {
            SiteBuilder::removeDirectory($staging);
        } else {
            fwrite(STDOUT, "Staging kept at {$staging}\n");
        }
    }

    fwrite(STDOUT, sprintf(
        "\nSite release: %s\n  files: %d\n  size: %.1f MB\n  sha256: %s\n",
        $result['path'],
        $result['files'],
        $result['bytes'] / 1048576,
        $result['sha256'],
    ));

    exit(0);
}

$archive = $out . '/polaris-kernel-' . $version . '.zip';

$result = Packager::build($root, $archive, $version, [
    '.htaccess',
    'index.php',
    'install.php',
    'src',
    'config',
    'resources',
    'assets',
    'database',
    'bin',
    'docs',
    'composer.json',
    'composer.lock',
    'VERSION',
    'LICENSE',
    'README.md',
    'AGENTS.md',
    'CHANGELOG.md',
    'SECURITY.md',
    'CONTRIBUTING.md',
    'TRADEMARK.md',
    '.env.example',
    'phpunit.xml',
]);

fwrite(STDOUT, sprintf(
    "\nKernel release: %s\n  files: %d\n  size: %.1f MB\n  sha256: %s\n",
    $result['path'],
    $result['files'],
    $result['bytes'] / 1048576,
    $result['sha256'],
));
