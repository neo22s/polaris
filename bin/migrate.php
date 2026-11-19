<?php

declare(strict_types=1);

/*
 * Apply the pending SQL upgrades.
 *
 * Usage:
 *   php bin/migrate.php             run the pending files
 *   php bin/migrate.php --dry-run   list them and change nothing
 *
 * Works both inside the kernel repository (where it is the package root) and
 * inside a site that vendors the kernel (where the kernel is a directory), so
 * the same command covers a fresh developer machine, the installer and the
 * deploy runner.
 */

use Polaris\Deploy\MigrationRunner;
use Polaris\Support\Config;
use Polaris\Support\Database;
use Polaris\Support\Env;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

// Inside a site, ./kernel holds the package; inside the package, we are it.
$kernelRoot = is_dir($root . '/kernel/src') ? $root . '/kernel' : $root;
$siteRoot = $root;

Env::load($siteRoot . '/.env');

$defaults = require $kernelRoot . '/config/defaults.php';
$overrides = is_file($siteRoot . '/config/settings.php')
    ? (require $siteRoot . '/config/settings.php')
    : [];

$config = Config::merge($defaults, $overrides);
$db = new Database($config['settings']['db']);

// Reuse the same directory rules as the installer and the deploy runner, so
// `composer migrate` sees database/sql as well as database/upgrades.
$directories = MigrationRunner::siteDirectories($siteRoot);

if (in_array('--dry-run', $argv ?? [], true)) {
    foreach (MigrationRunner::pendingFiles($db, $directories) as $name) {
        fwrite(STDOUT, $name . "\n");
    }

    exit(0);
}

$ran = MigrationRunner::run($db, $directories);

if ($ran === []) {
    fwrite(STDOUT, "Nothing to migrate.\n");
    exit(0);
}

foreach ($ran as $name) {
    fwrite(STDOUT, 'Applied ' . $name . "\n");
}
