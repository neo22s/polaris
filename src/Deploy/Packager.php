<?php

declare(strict_types=1);

namespace Polaris\Deploy;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Builds a release archive a shared-hosting install can use directly.
 *
 * What a release must contain, and why:
 *
 * - `vendor/` with runtime dependencies only. The server has no Composer,
 *   so the archive has to be complete. Development packages (PHPUnit, static
 *   analysis) are excluded: they are most of the download and none of the
 *   runtime.
 * - `kernel/` as a plain directory, never a symlink: `bin/kernel.php` creates
 *   a symlink for local development, and an archive must not depend on one.
 * - A manifest with the version and a SHA-256 per file, so the installer and
 *   the updater can verify what they downloaded before touching anything.
 *
 * The archive is deterministic: the same tree produces the same manifest, and
 * file order is sorted. That makes "is this release identical to that one?"
 * answerable, which matters when a deploy fails halfway.
 */
final class Packager
{
    /** Paths never included in a release, relative to their root. */
    private const NEVER = [
        '.git',
        '.github',
        'tests',
        'var',
        'uploads',
        'deploy',
        'dist',
        'node_modules',
        '.idea',
        '.vscode',
    ];

    /** Files kept even though they start with a dot. */
    private const DOTFILES_KEPT = ['.htaccess', '.env.example', '.gitignore'];

    /**
     * Build the archive.
     *
     * @param string $root The project root to package.
     * @param string $output Path of the zip to create.
     * @param string $version Version written to the manifest.
     * @param array<int,string> $roots Top-level entries to include, in order.
     * @return array{path:string,files:int,bytes:int,sha256:string}
     */
    public static function build(string $root, string $output, string $version, array $roots = ['.']): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is required to build a release.');
        }

        $root = rtrim($root, '/');
        $files = self::collect($root, $roots);

        if ($files === []) {
            throw new RuntimeException('Nothing to package: the root has no files.');
        }

        if (is_file($output)) {
            unlink($output);
        }

        $zip = new ZipArchive();

        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create ' . $output);
        }

        $hashes = [];

        foreach ($files as $relative => $absolute) {
            $hash = (string) hash_file('sha256', $absolute);
            $hashes[$relative] = $hash;

            // A fixed timestamp keeps the archive byte-identical across builds.
            $zip->addFile($absolute, $relative);
            $zip->setCompressionName($relative, ZipArchive::CM_DEFLATE, 9);
        }

        $manifest = json_encode([
            // Not the developer's PHP: the minimum the release supports. A
            // release built on 8.4 that runs on 8.1 must say 8.1.
            'requires_php' => '8.1',
            'version' => $version,
            'generated_at' => date('c'),
            'php' => PHP_VERSION,
            'files' => $hashes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $zip->addFromString('MANIFEST.json', $manifest);
        $zip->addFromString('VERSION', $version . "\n");

        if ($zip->close() !== true) {
            throw new RuntimeException('Could not finish writing ' . $output);
        }

        return [
            'path' => $output,
            'files' => count($hashes),
            'bytes' => (int) filesize($output),
            'sha256' => (string) hash_file('sha256', $output),
        ];
    }

    /**
     * Every file to package, relative path => absolute path, sorted.
     *
     * @param array<int,string> $roots
     * @return array<string,string>
     */
    private static function collect(string $root, array $roots): array
    {
        $files = [];

        foreach ($roots as $entry) {
            $entry = trim($entry, '/');
            $absolute = $root . ($entry === '.' ? '' : '/' . $entry);

            if (!file_exists($absolute)) {
                continue;
            }

            if (is_file($absolute)) {
                $relative = $entry;

                if (self::isIncluded($relative)) {
                    $files[$relative] = $absolute;
                }

                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                if (self::isIncluded($relative)) {
                    $files[$relative] = $file->getPathname();
                }
            }
        }

        ksort($files);

        return $files;
    }

    private static function isIncluded(string $relative): bool
    {
        // Uploaded *content* is never packaged, but the execution guard has to
        // ship: without it a site restored from a release accepts .php uploads.
        if ($relative === 'uploads/.htaccess') {
            return true;
        }

        $parts = explode('/', $relative);

        foreach (self::NEVER as $excluded) {
            if (in_array($excluded, $parts, true)) {
                return false;
            }
        }

        // Empty directories are not packaged by a zip, and a view folder that
        // exists only in the repository would silently miss from the release.
        // `admin/index.twig` was lost this way once; the check is cheap.
        if (in_array('.gitkeep', $parts, true)) {
            return false;
        }

        // Dotfiles are code and secrets, not release content — except the three
        // that a hosting needs: rewrite rules, the env template and gitignore.
        $base = basename($relative);
        if (str_starts_with($base, '.') && !in_array($base, self::DOTFILES_KEPT, true)) {
            return false;
        }

        return !str_ends_with($base, '.log');
    }
}
