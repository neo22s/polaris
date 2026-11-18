<?php

declare(strict_types=1);

namespace Polaris\Deploy;

use RuntimeException;

/**
 * Stages a site so it can be packaged without development dependencies.
 *
 * The developer's `vendor/` contains PHPUnit, static analysis and every tool a
 * deploy never needs. Packaging it as-is would ship ~20 MB of dev code to a
 * shared host. This copies the site's runtime sources into a temporary
 * directory and runs `composer install --no-dev` there, so the archive is
 * built from a tree that mirrors the server instead of the laptop.
 *
 * The site itself is never modified: `--no-dev` runs against the copy.
 */
final class SiteBuilder
{
    /**
     * What a vendored kernel contains.
     *
     * Not the whole kernel repository: it also holds `starter/`, which in a
     * development checkout is a symlink back to the kernel, and copying it
     * would recurse until the disk is full. These entries are the release
     * layout, which is exactly what a site needs.
     */
    private const KERNEL_ENTRIES = [
        'src',
        'config',
        'resources',
        'database',
        'bin',
        'composer.json',
        'composer.lock',
        'VERSION',
        'LICENSE',
        'README.md',
        'AGENTS.md',
        'SECURITY.md',
        'TRADEMARK.md',
        'CONTRIBUTING.md',
    ];

    /** Entries copied into the staging tree. */
    private const SOURCES = [
        '.htaccess',
        'index.php',
        'app',
        'assets',
        'config',
        'database',
        'kernel',
        'resources',
        'composer.json',
        'composer.lock',
        'VERSION',
        'AGENTS.md',
    ];

    /**
     * @param string $siteRoot The site to stage.
     * @return string The staging directory (caller removes it).
     */
    public static function stage(string $siteRoot, string $composerBinary = 'composer'): string
    {
        $siteRoot = rtrim($siteRoot, '/');
        $staging = sys_get_temp_dir() . '/polaris-stage-' . bin2hex(random_bytes(6));

        if (!mkdir($staging, 0775, true) && !is_dir($staging)) {
            throw new RuntimeException('Could not create the staging directory.');
        }

        foreach (self::SOURCES as $entry) {
            $source = $siteRoot . '/' . $entry;

            if (!file_exists($source)) {
                continue;
            }

            if (is_file($source)) {
                copy($source, $staging . '/' . $entry);
                continue;
            }

            if ($entry === 'kernel') {
                self::copyKernel($source, $staging . '/kernel');
                continue;
            }

            self::copyDirectory($source, $staging . '/' . $entry);
        }

        // `composer install` with no scripts: a plugin that needs the host
        // environment must not run during packaging.
        $command = sprintf(
            'cd %s && COMPOSER_MEMORY_LIMIT=-1 %s install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader 2>&1',
            escapeshellarg($staging),
            escapeshellarg($composerBinary),
        );

        exec($command, $output, $code);

        if ($code !== 0) {
            self::removeDirectory($staging);

            throw new RuntimeException(
                "composer install --no-dev failed:\n" . implode("\n", array_slice($output, -20)),
            );
        }

        return $staging;
    }

    /**
     * Verify the staged tree has no development packages.
     *
     * @return array<int,string> The dev package names found, empty when clean.
     */
    public static function developmentPackages(string $staging): array
    {
        $installed = $staging . '/vendor/composer/installed.json';

        if (!is_file($installed)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($installed), true);
        $packages = $data['packages'] ?? $data;
        $names = [];

        foreach ((array) $packages as $package) {
            if (!empty($package['dev'])) {
                $names[] = (string) ($package['name'] ?? '');
            }
        }

        return $names;
    }

    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? @rmdir($entry->getPathname())
                : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }

    /**
     * Copy a kernel directory without following anything back into itself.
     */
    private static function copyKernel(string $kernelRoot, string $destination): void
    {
        $real = realpath($kernelRoot) ?: $kernelRoot;

        foreach (self::KERNEL_ENTRIES as $entry) {
            $source = $real . '/' . $entry;

            if (!file_exists($source)) {
                continue;
            }

            if (is_file($source)) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0775, true);
                }

                copy($source, $destination . '/' . $entry);
                continue;
            }

            self::copyDirectory($source, $destination . '/' . $entry);
        }
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new RuntimeException('Could not create ' . $destination);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            $target = $destination . '/' . substr($entry->getPathname(), strlen($source) + 1);

            // A symlink is never copied: a `kernel` or `uploads` link that
            // points back into the tree would recurse forever.
            if ($entry->isLink()) {
                continue;
            }

            if ($entry->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new RuntimeException('Could not create ' . $target);
                }

                continue;
            }

            // Follow a symlinked kernel during development: the release must
            // contain the files, not a link to the developer's checkout.
            copy($entry->getPathname(), $target);
        }
    }
}
