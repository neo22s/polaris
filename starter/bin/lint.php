<?php

declare(strict_types=1);

/*
 * PHP syntax check for the site and the vendored kernel.
 *
 * A syntax error in a template-adjacent file ships silently otherwise: the page
 * 500s and the log line is buried. This runs in the deploy gate.
 */

$root = dirname(__DIR__);
$directories = [
    'app',
    'bin',
    'config',
    'database',
    'tests',
    'kernel/src',
    'kernel/config',
    'kernel/bin',
    'kernel/resources/stubs',
];
$files = [];

foreach ($directories as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

foreach (['index.php', 'install.php'] as $entry) {
    if (is_file($root . '/' . $entry)) {
        $files[] = $root . '/' . $entry;
    }
}

sort($files);

$failed = [];

foreach ($files as $file) {
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);

    if ($code !== 0) {
        $failed[$file] = implode("\n", $output);
    }
}

if ($failed !== []) {
    foreach ($failed as $file => $output) {
        fwrite(STDERR, $output . "\n");
    }

    fwrite(STDERR, sprintf("PHP syntax failed in %d file(s).\n", count($failed)));
    exit(1);
}

fwrite(STDOUT, sprintf("PHP syntax OK (%d files).\n", count($files)));
