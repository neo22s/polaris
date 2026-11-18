<?php

declare(strict_types=1);

/*
 * Links the kernel repository into a site for local development.
 *
 * A site has `kernel/` at its root: in a release that is a plain directory, and
 * during development it is a symlink to the kernel repository, so a kernel
 * change is visible without copying files or running composer.
 *
 * Usage (from the site root):
 *   php bin/kernel.php ../polaris
 *
 * The link is git-ignored: a clone creates it with this command.
 */

$root = dirname(__DIR__);
$target = $argv[1] ?? '../polaris';

$absolute = realpath($root . '/' . $target);

if ($absolute === false || !is_dir($absolute . '/src')) {
    fwrite(STDERR, sprintf("Not a Polaris kernel: %s\n", $root . '/' . $target));
    exit(1);
}

if (!is_file($absolute . '/VERSION')) {
    fwrite(STDERR, sprintf("Missing %s/VERSION — is this the kernel repository?\n", $absolute));
    exit(1);
}

$link = $root . '/kernel';

if (is_link($link)) {
    unlink($link);
} elseif (is_dir($link)) {
    fwrite(STDERR, "kernel/ is a real directory; refusing to replace it.\n");
    exit(1);
}

if (!symlink($absolute, $link)) {
    fwrite(STDERR, "Could not create the kernel symlink.\n");
    exit(1);
}

fwrite(STDOUT, sprintf("kernel -> %s\n", $absolute));
